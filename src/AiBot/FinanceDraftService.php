<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Payday3\Contracts\AuditLogInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\NamedLockInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterLookupServiceInterface;
use App\Payday3\Contracts\PosterTransactionCreateServiceInterface;
use App\Payday3\Domain\Actor;

/**
 * Черновик расходов «Инвесторы» из Telegram: карточка, выбор счёта/даты,
 * режим «одна запись на человека / по переводам», внесение в Poster.
 *
 * Гарантии:
 *   - один черновик на (chat_id, source_msg_id, intent) — UNIQUE в БД;
 *   - внесение под MySQL named lock, статус draft→executing→done/partial;
 *   - у каждой записи свой статус и Poster tx id; при повторе записи в
 *     статусе done/dup не отправляются заново;
 *   - перед записью — сверка с Poster (тот же счёт, категория 22, ±3 дня,
 *     та же сумма и имя получателя словом в комментарии): совпавшее — «возможный
 *     дубль» с данными исходной транзакции, по умолчанию НЕ вносится; черновик
 *     ждёт решения (review). Повтор вносится только по явной кнопке владельца,
 *     решение пишется в аудит; повторный callback ничего не меняет.
 */
final class FinanceDraftService
{
    public const INTENT = 'investor_dividends';

    public const SPLIT_PERSON = 'person';
    public const SPLIT_PARTS = 'parts';

    private const DUP_WINDOW_DAYS = 3;
    private const DATE_PICK_DAYS = 6;
    private const EXEC_STALE_SEC = 120;
    public const MAX_PEOPLE = 30;

    /** Записи, которые execute() не трогает: внесены, ждут решения по дублю, пропущены владельцем, исход не определён (нужна сверка). */
    private const SETTLED = ['done', 'dup', 'skipped', 'unverified'];

    /** @var array<int,string>|null */
    private ?array $accountNames = null;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly FinanceDraftRepositoryInterface $drafts,
        private readonly PosterTransactionCreateServiceInterface $creator,
        private readonly PosterApiProviderInterface $poster,
        private readonly LocalSettingsRepositoryInterface $settings,
        private readonly PosterLookupServiceInterface $lookup,
        private readonly AuditLogInterface $audit,
        private readonly NamedLockInterface $lock,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    // ─── создание ──────────────────────────────────────────────────────────

    /**
     * Найти или создать черновик для источника. Под локом источника, чтобы
     * повтор вебхука Telegram не отправил вторую карточку.
     *
     * @param array{people:list<array>,period:?string,errors:list<string>} $parsed
     * @param callable(array):?int $sendCard  отправить карточку → message_id
     * @return array{draft:array<string,mixed>, created:bool}
     */
    public function createOrGet(string $chatId, int $sourceMsgId, int $triggerMsgId, int $initiatorTgId, int $sourceDate, array $parsed, callable $sendCard): array
    {
        return $this->lock->synchronized('aibot_src_' . $chatId . '_' . $sourceMsgId, 5, function () use ($chatId, $sourceMsgId, $triggerMsgId, $initiatorTgId, $sourceDate, $parsed, $sendCard) {
            $existing = $this->drafts->findBySource($chatId, $sourceMsgId, self::INTENT);
            if ($existing !== null) {
                return ['draft' => $existing, 'created' => false];
            }
            $hasParts = false;
            foreach ($parsed['people'] as $p) {
                $hasParts = $hasParts || ($p['parts'] ?? []) !== [];
            }
            $id = $this->drafts->insert([
                'chat_id' => $chatId,
                'source_msg_id' => $sourceMsgId,
                'trigger_msg_id' => $triggerMsgId,
                'initiator_tg_id' => $initiatorTgId,
                'intent' => self::INTENT,
                'rows_json' => self::enc([
                    'people' => array_values($parsed['people']),
                    'period' => $parsed['period'] ?? null,
                    'errors' => array_values($parsed['errors'] ?? []),
                    'source_date' => $sourceDate,
                ]),
                // Явно показанные переводы → по умолчанию отдельной записью на каждый.
                'split_mode' => $hasParts ? self::SPLIT_PARTS : self::SPLIT_PERSON,
                'status' => 'draft',
            ]);
            $draft = (array) $this->drafts->get($id);
            $cardId = $sendCard($draft);
            if ($cardId !== null && $cardId > 0) {
                $this->drafts->update($id, ['card_msg_id' => $cardId]);
                $draft['card_msg_id'] = $cardId;
            }
            return ['draft' => $draft, 'created' => true];
        });
    }

    public function get(int $id): ?array
    {
        return $this->drafts->get($id);
    }

    public function attachCard(int $id, int $cardMsgId): void
    {
        $this->drafts->update($id, ['card_msg_id' => $cardMsgId]);
    }

    // ─── кнопки ────────────────────────────────────────────────────────────

    /**
     * Кнопка выбора/переключения. Возвращает текст всплывашки.
     * Ничего не меняет после начала внесения (записи заморожены).
     */
    public function applyChoice(int $draftId, string $action, string $arg): string
    {
        // Тот же лок, что у execute(): выбор/отмена не может проскочить между
        // проверкой статуса и началом внесения.
        return $this->lock->synchronized('aibot_fd_' . $draftId, 10, fn() => $this->applyChoiceLocked($draftId, $action, $arg));
    }

    private function applyChoiceLocked(int $draftId, string $action, string $arg): string
    {
        $draft = $this->drafts->get($draftId);
        if ($draft === null) {
            return 'Черновик не найден';
        }
        if ((string) $draft['status'] !== 'draft') {
            return 'Черновик уже ' . self::statusLabel((string) $draft['status']) . ' — менять нельзя';
        }
        switch ($action) {
            case 'acc':
                $acc = (int) $arg;
                if (!isset($this->accountChoices()[$acc])) {
                    return 'Этот счёт не разрешён';
                }
                $this->drafts->update($draftId, ['account_id' => $acc]);
                return 'Счёт: ' . $this->accountChoices()[$acc];
            case 'date':
                $date = $this->resolveDate($arg);
                if ($date === null) {
                    return 'Недопустимая дата';
                }
                $this->drafts->update($draftId, ['tx_date' => $date]);
                return 'Дата: ' . self::dm($date);
            case 'split':
                $mode = (string) $draft['split_mode'] === self::SPLIT_PARTS ? self::SPLIT_PERSON : self::SPLIT_PARTS;
                $this->drafts->update($draftId, ['split_mode' => $mode]);
                return $mode === self::SPLIT_PARTS ? 'Запись на каждый перевод' : 'Одна запись на человека';
            case 'cancel':
                $this->drafts->update($draftId, ['status' => 'cancelled']);
                return 'Отменено';
        }
        return 'Неизвестное действие';
    }

    // ─── внесение ──────────────────────────────────────────────────────────

    /**
     * Внести черновик в Poster. Идемпотентно: повтор после done ничего не
     * шлёт; после partial досылает только незавершённые записи.
     *
     * @return array{draft:array<string,mixed>, message:string}
     */
    public function execute(int $draftId, int $actorTgId): array
    {
        return $this->lock->synchronized('aibot_fd_' . $draftId, 10, function () use ($draftId, $actorTgId) {
            $draft = $this->drafts->get($draftId);
            if ($draft === null) {
                return ['draft' => [], 'message' => 'Черновик не найден'];
            }
            $status = (string) $draft['status'];
            if ($status === 'done' || $status === 'cancelled') {
                return ['draft' => $draft, 'message' => 'Уже ' . self::statusLabel($status)];
            }
            if ($status === 'executing' && !$this->isStale($draft)) {
                return ['draft' => $draft, 'message' => 'Уже вносится — подождите'];
            }
            $blocker = $this->blocker($draft);
            if ($blocker !== null) {
                return ['draft' => $draft, 'message' => $blocker];
            }

            $records = self::dec($draft['poster_tx_ids_json'] ?? null);
            if ($records === []) {
                $records = $this->buildRecords($draft);
            }
            $accountId = (int) $draft['account_id'];
            $date = (string) $draft['tx_date'];

            // Сверка с Poster ДО записи. Не смогли проверить — не пишем (fail closed).
            try {
                $records = $this->markDuplicates($records, $accountId, $date);
            } catch (\Throwable $e) {
                $this->drafts->update($draftId, ['error' => 'Проверка дублей в Poster не удалась: ' . mb_substr($e->getMessage(), 0, 300)]);
                return ['draft' => (array) $this->drafts->get($draftId), 'message' => 'Не смог проверить дубли в Poster — ничего не внесено'];
            }

            $this->drafts->update($draftId, ['status' => 'executing', 'poster_tx_ids_json' => self::enc($records), 'error' => null, 'heartbeat_at' => ($this->clock)()]);

            $datetime = $this->txDateTime($date);
            foreach ($records as $i => $rec) {
                if (in_array($rec['status'], self::SETTLED, true)) {
                    continue;
                }
                // «sending» фиксируется ДО вызова: если процесс умрёт посреди, при
                // повторе запись не считается внесённой, но сверка с Poster выше
                // поймает её, если Poster транзакцию всё-таки создал.
                $records[$i]['status'] = 'sending';
                $this->drafts->update($draftId, ['poster_tx_ids_json' => self::enc($records), 'heartbeat_at' => ($this->clock)()]);
                try {
                    $res = $this->creator->create([
                        'type' => 2, // расход (UI-тип payday3)
                        'amount' => (int) $rec['amount'],
                        'date' => $datetime,
                        'comment' => (string) $rec['comment'],
                        'category_id' => AiBotConfig::CATEGORY_ID,
                        'account_from' => $accountId,
                    ], new Actor('tg:' . $actorTgId, 'tgdraft:' . $draftId . ':' . $i));
                    $records[$i]['status'] = 'done';
                    $records[$i]['tx_ids'] = array_values(array_filter([self::txId($res['response'] ?? null)]));
                    unset($records[$i]['error']);
                } catch (\Throwable $e) {
                    $records[$i]['status'] = 'failed';
                    $records[$i]['error'] = mb_substr($e->getMessage(), 0, 200);
                }
                $this->drafts->update($draftId, ['poster_tx_ids_json' => self::enc($records)]);
            }

            $failed = count(array_filter($records, static fn(array $r) => $r['status'] === 'failed'));
            $open = count(array_filter($records, static fn(array $r) => $r['status'] === 'dup'));
            // Возможные дубли не вносятся — ждут явного решения владельца (review).
            $unknown = count(array_filter($records, static fn(array $r) => $r['status'] === 'unverified'));
            $final = $failed > 0 ? 'partial' : ($open > 0 ? 'review' : ($unknown > 0 ? 'reconcile' : 'done'));
            $this->drafts->update($draftId, ['status' => $final, 'poster_tx_ids_json' => self::enc($records)]);

            try {
                $this->audit->record('tg:' . $actorTgId, 'aibot.finance_draft.execute', [
                    'draft_id' => $draftId,
                    'chat_id' => $draft['chat_id'],
                    'source_msg_id' => (int) $draft['source_msg_id'],
                    'account_id' => $accountId,
                    'category_id' => AiBotConfig::CATEGORY_ID,
                    'date' => $datetime,
                    'status' => $final,
                    'records' => $records,
                ]);
            } catch (\Throwable $e) {
                error_log('[aibot.finance] audit write failed: ' . $e->getMessage());
            }

            return [
                'draft' => (array) $this->drafts->get($draftId),
                'message' => match ($final) {
                    'done' => 'Готово',
                    'review' => 'Есть возможные дубли — решите, повторять ли',
                    'reconcile' => 'Результат части записей не определён — нужна сверка с Poster',
                    default => 'Часть записей не внесена — можно повторить',
                },
            ];
        });
    }

    // ─── карточка ──────────────────────────────────────────────────────────

    /** @return array{text:string, keyboard:list<list<array{text:string,callback_data:string}>>} */
    public function render(array $draft): array
    {
        $id = (int) $draft['id'];
        $data = self::dec($draft['rows_json'] ?? null);
        $people = (array) ($data['people'] ?? []);
        $period = $data['period'] ?? null;
        $status = (string) $draft['status'];
        $records = self::dec($draft['poster_tx_ids_json'] ?? null);

        $L = [];
        $L[] = '🧾 <b>Черновик #' . $id . '</b>: расход, категория «Инвесторы»';
        $L[] = 'Источник: сообщение #' . (int) $draft['source_msg_id']
            . (!empty($data['source_date']) ? ' от ' . $this->localDate((int) $data['source_date'], 'd.m.Y H:i') : '');
        if ($period) {
            $L[] = 'Период: ' . self::h((string) $period);
        }
        $L[] = '';
        $total = 0;
        foreach ($people as $n => $p) {
            $line = ($n + 1) . '. ' . self::h((string) $p['name']) . ' — ' . PayoutParser::fmt((int) $p['amount']);
            if (!empty($p['parts'])) {
                $line .= ' (переводы ' . implode(' + ', array_map([PayoutParser::class, 'fmt'], $p['parts'])) . ')';
            }
            if (!empty($p['error'])) {
                $line .= ' ⚠️ ' . self::h((string) $p['error']);
            }
            $L[] = $line;
            $total += (int) $p['amount'];
        }
        foreach ((array) ($data['errors'] ?? []) as $e) {
            $L[] = '⚠️ ' . self::h((string) $e);
        }
        $L[] = '<b>Итого: ' . PayoutParser::fmt($total) . ' ₫</b> · ' . count($people) . ' чел.';
        $L[] = '';

        $preview = $records !== [] ? $records : $this->buildRecords($draft);
        $L[] = 'Записей в Poster: ' . count($preview) . ((string) $draft['split_mode'] === self::SPLIT_PARTS ? ' (по переводам)' : ' (одна на человека)');
        $accId = (int) ($draft['account_id'] ?? 0);
        $L[] = 'Счёт: ' . ($accId > 0 ? self::h($this->accountChoices()[$accId] ?? ('#' . $accId)) : '— выберите —');
        $L[] = 'Дата: ' . (!empty($draft['tx_date']) ? self::dm((string) $draft['tx_date'], true) : '— выберите —');

        if ($status === 'draft') {
            $L[] = 'Комментарии:';
            foreach ($preview as $r) {
                $L[] = '  • ' . self::h((string) $r['comment']) . ' — ' . PayoutParser::fmt((int) $r['amount']);
            }
        } else {
            $L[] = '';
            $L[] = 'Статус: <b>' . self::statusLabel($status) . '</b>';
            foreach ($records as $r) {
                $L[] = self::recordLine($r);
            }
        }
        if (!empty($draft['error'])) {
            $L[] = '⚠️ ' . self::h((string) $draft['error']);
        }

        return ['text' => implode("\n", $L), 'keyboard' => $this->keyboard($draft, $people)];
    }

    /** Ответ-журнал после внесения: Poster tx id по каждой записи. */
    public function journal(array $draft): string
    {
        $L = ['📒 Черновик #' . (int) $draft['id'] . ' — ' . self::statusLabel((string) $draft['status'])];
        foreach (self::dec($draft['poster_tx_ids_json'] ?? null) as $r) {
            $L[] = self::recordLine($r);
        }
        return implode("\n", $L);
    }

    /** Почему «Внести» сейчас нельзя (null = можно). */
    public function blocker(array $draft): ?string
    {
        $data = self::dec($draft['rows_json'] ?? null);
        $people = (array) ($data['people'] ?? []);
        if ($people === []) {
            return 'Нет строк для внесения';
        }
        if (count($people) > self::MAX_PEOPLE) {
            return 'Слишком много строк (больше ' . self::MAX_PEOPLE . ')';
        }
        foreach ($people as $p) {
            if (!empty($p['error'])) {
                return 'Есть ошибки разбора — исправьте сообщение';
            }
        }
        if (!empty($data['errors'])) {
            return 'Есть ошибки разбора';
        }
        if ((int) ($draft['account_id'] ?? 0) <= 0) {
            return 'Выберите счёт';
        }
        // Настройки payday могли поменяться после выбора — счёт проверяется заново.
        if (!isset($this->accountChoices()[(int) $draft['account_id']])) {
            return 'Счёт больше не входит в настроенные — выберите заново';
        }
        if (empty($draft['tx_date'])) {
            return 'Выберите дату';
        }
        return null;
    }

    // ─── внутреннее ────────────────────────────────────────────────────────

    /** @return array<int,string> id → название; только счета из настроек payday. */
    public function accountChoices(): array
    {
        if ($this->accountNames !== null) {
            return $this->accountNames;
        }
        $names = [];
        try {
            $names = $this->lookup->financeAccounts();
        } catch (\Throwable) {
            // Нет связи с Poster — показываем id, выбор всё равно ограничен настройками.
        }
        $out = [];
        $ids = $this->settings->load()->configuredAccountIds();
        sort($ids);
        foreach ($ids as $id) {
            $out[$id] = trim((string) ($names[$id] ?? '')) ?: ('Счёт #' . $id);
        }
        return $this->accountNames = $out;
    }

    /** @return list<array<string,mixed>> */
    private function buildRecords(array $draft): array
    {
        $data = self::dec($draft['rows_json'] ?? null);
        $period = isset($data['period']) && $data['period'] !== '' ? ' ' . $data['period'] : '';
        $split = (string) $draft['split_mode'] === self::SPLIT_PARTS;
        $out = [];
        foreach ((array) ($data['people'] ?? []) as $p) {
            $base = 'Дивиденды' . $period . ' — ' . $p['name'];
            $parts = array_values((array) ($p['parts'] ?? []));
            if ($split && count($parts) > 1) {
                $n = count($parts);
                foreach ($parts as $k => $amt) {
                    $out[] = ['name' => $p['name'], 'amount' => (int) $amt, 'parts' => [],
                        'comment' => $base . ' (часть ' . ($k + 1) . '/' . $n . ')', 'status' => 'pending', 'tx_ids' => []];
                }
            } else {
                $out[] = ['name' => $p['name'], 'amount' => (int) $p['amount'], 'parts' => $parts,
                    'comment' => $base, 'status' => 'pending', 'tx_ids' => []];
            }
        }
        return $out;
    }

    /**
     * Сверка с уже существующими расходами Poster. Каждая транзакция Poster
     * может «закрыть» только одну запись. Запись «на человека» с переводами
     * считается внесённой и тогда, когда в Poster лежат все её части.
     *
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private function markDuplicates(array $records, int $accountId, string $date): array
    {
        $pending = array_filter($records, static fn(array $r) => !in_array($r['status'], self::SETTLED, true));
        if ($pending === []) {
            return $records;
        }
        $d = new \DateTimeImmutable($date, new \DateTimeZone(AiBotConfig::TZ));
        // Пагинации у finance.getTransactions в коде проекта нет (ExpenseService берёт
        // месяц одним вызовом; per_page/limit есть только у transactions./dash.getTransactions).
        // Здесь окно 7 дней по одному счёту — объём на порядки меньше месяца.
        $rows = $this->poster->client()->request('finance.getTransactions', [
            'dateFrom' => $d->modify('-' . self::DUP_WINDOW_DAYS . ' days')->format('Ymd'),
            'dateTo' => $d->modify('+' . self::DUP_WINDOW_DAYS . ' days')->format('Ymd'),
            'account_id' => $accountId,
            'timezone' => 'client',
        ]);
        if (!is_array($rows)) {
            throw new \RuntimeException('неожиданный ответ finance.getTransactions');
        }

        $own = [];
        foreach ($records as $r) {
            foreach ((array) ($r['tx_ids'] ?? []) as $tid) {
                $own[(int) $tid] = true;
            }
            // Исходная транзакция уже показанного/решённого дубля закрывает только свою запись.
            foreach ((array) ($r['dup_of'] ?? []) as $t) {
                $own[(int) ($t['id'] ?? 0)] = true;
            }
        }
        $pool = []; // tx_id → {amount VND, comment, date}
        foreach ($rows as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tid = (int) ($t['transaction_id'] ?? 0);
            if ($tid <= 0 || isset($own[$tid]) || (int) ($t['category_id'] ?? 0) !== AiBotConfig::CATEGORY_ID) {
                continue;
            }
            $acc = (int) ($t['account_id'] ?? $accountId);
            if ($acc !== $accountId) {
                continue;
            }
            $pool[$tid] = [
                // finance.getTransactions отдаёт сумму в копейках (×100), расход — со знаком «−».
                'amount' => (int) round(abs((float) ($t['amount'] ?? 0)) / 100),
                'comment' => trim((string) ($t['comment'] ?? '')),
                'date' => (string) ($t['date'] ?? ''),
            ];
        }

        foreach ($records as $i => $r) {
            $st = (string) $r['status'];
            if (in_array($st, self::SETTLED, true)) {
                continue;
            }
            // Отправка с неизвестным исходом (sending — упали после вызова, failed —
            // ошибка/таймаут, но Poster мог создать). Уникального признака операции в
            // комментарии нет, поэтому «свою» транзакцию однозначно узнать нельзя:
            // любая транзакция той же суммы и того же получателя (или с тем же
            // комментарием) делает исход неопределённым. Такая запись НЕ становится
            // done и НЕ отправляется повторно — владельцу сообщается «нужна сверка».
            // Кандидатов нет — Poster запись точно не создал, отправка безопасна.
            if ($st === 'sending' || $st === 'failed') {
                $cand = self::uncertainCandidates($pool, (int) $r['amount'], (string) $r['name'], (string) $r['comment']);
                if ($cand !== []) {
                    $records[$i]['status'] = 'unverified';
                    $records[$i]['candidates'] = array_map(static fn(int $id) => ['id' => $id] + $pool[$id], $cand);
                    continue;
                }
            }
            // Владелец разрешил повтор: исходную транзакцию (dup_of) больше не сравниваем.
            if (!empty($r['repeat_ok'])) {
                continue;
            }
            $ids = [];
            $hit = self::findMatch($pool, (int) $r['amount'], (string) $r['name']);
            if ($hit !== null) {
                $ids = [$hit];
            } else {
                $parts = (array) ($r['parts'] ?? []);
                if (count($parts) > 1) {
                    $try = $pool;
                    foreach ($parts as $amt) {
                        $h = self::findMatch($try, (int) $amt, (string) $r['name']);
                        if ($h === null) {
                            $ids = [];
                            break;
                        }
                        unset($try[$h]);
                        $ids[] = $h;
                    }
                }
            }
            if ($ids === []) {
                continue;
            }
            $records[$i]['status'] = 'dup';
            $records[$i]['tx_ids'] = [];
            $records[$i]['dup_of'] = array_map(static fn(int $id) => ['id' => $id] + $pool[$id], $ids);
            foreach ($ids as $id) {
                unset($pool[$id]);
            }
        }
        return $records;
    }

    /**
     * Транзакция Poster той же суммы, в комментарии которой есть имя получателя.
     *
     * @param array<int,array{amount:int,comment:string,date:string}> $pool
     * @param array<int,mixed> $skip tx_id, которые не рассматривать
     */
    private static function findMatch(array $pool, int $amount, string $name, array $skip = []): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        foreach ($pool as $tid => $t) {
            if (isset($skip[$tid]) || $t['amount'] !== $amount) {
                continue;
            }
            if (preg_match('/(?<!\p{L})' . preg_quote($name, '/') . '(?!\p{L})/iu', $t['comment'])) {
                return (int) $tid;
            }
        }
        return null;
    }

    /**
     * Транзакции, которые могли быть результатом нашей неудачной/оборванной
     * отправки: та же сумма и (тот же комментарий после нормализации ИЛИ имя
     * получателя словом в комментарии). Уже занятые ($own) в $pool не попадают.
     *
     * @param array<int,array{amount:int,comment:string,date:string}> $pool
     * @return list<int>
     */
    private static function uncertainCandidates(array $pool, int $amount, string $name, string $comment): array
    {
        $norm = self::normComment($comment);
        $out = [];
        foreach ($pool as $tid => $t) {
            if ($t['amount'] !== $amount) {
                continue;
            }
            if (($norm !== '' && self::normComment($t['comment']) === $norm)
                || self::findMatch([$tid => $t], $amount, $name) !== null) {
                $out[] = (int) $tid;
            }
        }
        return $out;
    }
    /** Сравнение комментариев без учёта регистра, HTML-сущностей, пробелов и вида тире. */
    private static function normComment(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/[\x{2010}-\x{2015}\x{2212}-]+/u', '-', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return mb_strtolower(trim($s), 'UTF-8');
    }

    // ─── возможные дубли: решение владельца ────────────────────────────────

    /**
     * Решение по записи-«возможному дублю»: 'rep' — внести повтор, 'skip' — не
     * вносить. Только для статуса dup; повторный callback ничего не меняет.
     * Повтор не отправляется здесь — после принятого 'rep' вызывающий запускает
     * execute(). Решение пишется в аудит ДО изменения (не записалось — не применяем).
     *
     * @return array{0:string,1:bool} [текст, решение принято этим вызовом]
     */
    public function decideDuplicate(int $draftId, int $index, string $decision, int $actorTgId): array
    {
        return $this->lock->synchronized('aibot_fd_' . $draftId, 10, function () use ($draftId, $index, $decision, $actorTgId) {
            $draft = $this->drafts->get($draftId);
            if ($draft === null) {
                return ['Черновик не найден', false];
            }
            if ((string) $draft['status'] !== 'review') {
                return ['Решение уже не требуется', false];
            }
            $records = self::dec($draft['poster_tx_ids_json'] ?? null);
            if (!isset($records[$index]) || (string) $records[$index]['status'] !== 'dup') {
                return ['По этой записи решение уже принято', false];
            }
            // Аудит ДО изменения: не записали решение — не применяем (fail closed).
            // Если затем упадёт update, в аудите останется неприменённое решение, а
            // повторное нажатие запишет второе — это осознанный выбор в пользу fail closed.
            try {
                $this->audit->record('tg:' . $actorTgId, 'aibot.finance_draft.duplicate_decision', [
                    'draft_id' => $draftId,
                    'chat_id' => $draft['chat_id'],
                    'record' => $index,
                    'decision' => $decision === 'rep' ? 'repeat' : 'skip',
                    'amount' => (int) $records[$index]['amount'],
                    'comment' => (string) $records[$index]['comment'],
                    'dup_of' => $records[$index]['dup_of'] ?? [],
                ]);
            } catch (\Throwable $e) {
                error_log('[aibot.finance] audit write failed: ' . $e->getMessage());
                return ['Не удалось записать решение в аудит — ничего не изменено', false];
            }
            if ($decision === 'rep') {
                $records[$index]['status'] = 'pending';
                $records[$index]['repeat_ok'] = true;
                $toast = 'Повтор разрешён — вношу';
            } else {
                $records[$index]['status'] = 'skipped';
                $toast = 'Не вношу';
            }
            $open = count(array_filter($records, static fn(array $r) => $r['status'] === 'dup'));
            $pending = count(array_filter($records, static fn(array $r) => in_array($r['status'], ['pending', 'failed', 'sending'], true)));
            $unknown = count(array_filter($records, static fn(array $r) => $r['status'] === 'unverified'));
            $status = $pending > 0 ? 'partial' : ($open > 0 ? 'review' : ($unknown > 0 ? 'reconcile' : 'done'));
            $this->drafts->update($draftId, ['status' => $status, 'poster_tx_ids_json' => self::enc($records)]);
            return [$toast, true];
        });
    }

    /** «executing» без движения дольше EXEC_STALE_SEC — процесс умер посреди внесения. */
    public function isStale(array $draft): bool
    {
        return (string) $draft['status'] === 'executing'
            && ($this->clock)() - (int) ($draft['heartbeat_at'] ?? 0) > self::EXEC_STALE_SEC;
    }

    private function keyboard(array $draft, array $people): array
    {
        $id = (int) $draft['id'];
        $status = (string) $draft['status'];
        if ($status === 'review') {
            $kb = [];
            foreach (self::dec($draft['poster_tx_ids_json'] ?? null) as $i => $r) {
                if ((string) $r['status'] !== 'dup') {
                    continue;
                }
                $label = mb_substr((string) $r['name'], 0, 20) . ' ' . PayoutParser::fmt((int) $r['amount']);
                $kb[] = [
                    ['text' => '🔁 Повторить: ' . $label, 'callback_data' => 'fd_rep:' . $id . ':' . $i],
                    ['text' => '✖️ Не вносить', 'callback_data' => 'fd_skip:' . $id . ':' . $i],
                ];
            }
            return $kb;
        }
        if ($status === 'partial' || ($status === 'executing' && $this->isStale($draft))) {
            return [[['text' => '🔁 Повторить незавершённые', 'callback_data' => 'fd_go:' . $id]]];
        }
        if ($status !== 'draft') {
            return [];
        }
        $kb = [];
        $row = [];
        foreach ($this->accountChoices() as $acc => $name) {
            $mark = (int) ($draft['account_id'] ?? 0) === $acc ? '✅ ' : '';
            $row[] = ['text' => $mark . $name, 'callback_data' => 'fd_acc:' . $id . ':' . $acc];
            if (count($row) === 3) {
                $kb[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $kb[] = $row;
        }

        $chosen = (string) ($draft['tx_date'] ?? '');
        $row = [];
        foreach ($this->dateChoices($draft) as $ymd => $label) {
            $mark = $chosen === $ymd ? '✅ ' : '';
            $row[] = ['text' => $mark . $label, 'callback_data' => 'fd_date:' . $id . ':' . str_replace('-', '', $ymd)];
            if (count($row) === 4) {
                $kb[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $kb[] = $row;
        }

        $hasParts = false;
        foreach ($people as $p) {
            $hasParts = $hasParts || !empty($p['parts']);
        }
        if ($hasParts) {
            $kb[] = [['text' => (string) $draft['split_mode'] === self::SPLIT_PARTS
                ? '🔀 Сейчас: по переводам → сделать 1 на человека'
                : '🔀 Сейчас: 1 на человека → сделать по переводам', 'callback_data' => 'fd_split:' . $id]];
        }

        $last = [];
        if ($this->blocker($draft) === null) {
            $last[] = ['text' => '✅ Внести', 'callback_data' => 'fd_go:' . $id];
        }
        $last[] = ['text' => '✖️ Отмена', 'callback_data' => 'fd_cancel:' . $id];
        $kb[] = $last;
        return $kb;
    }

    /** @return array<string,string> 'Y-m-d' → подпись */
    private function dateChoices(array $draft): array
    {
        $today = $this->localDate(($this->clock)(), 'Y-m-d');
        $out = [$today => 'Сегодня ' . self::dm($today)];
        $data = self::dec($draft['rows_json'] ?? null);
        if (!empty($data['source_date'])) {
            $src = $this->localDate((int) $data['source_date'], 'Y-m-d');
            if ($src !== $today) {
                $out[$src] = 'Сообщ. ' . self::dm($src);
            }
        }
        $d = new \DateTimeImmutable($today, new \DateTimeZone(AiBotConfig::TZ));
        for ($k = 1; $k <= self::DATE_PICK_DAYS; $k++) {
            $ymd = $d->modify('-' . $k . ' days')->format('Y-m-d');
            $out[$ymd] ??= self::dm($ymd);
        }
        return $out;
    }

    /** 'YYYYMMDD' → 'Y-m-d', только не в будущем и не старше 31 дня. */
    private function resolveDate(string $arg): ?string
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $arg, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $ymd = $m[1] . '-' . $m[2] . '-' . $m[3];
        $today = $this->localDate(($this->clock)(), 'Y-m-d');
        $min = (new \DateTimeImmutable($today))->modify('-31 days')->format('Y-m-d');
        return ($ymd > $today || $ymd < $min) ? null : $ymd;
    }

    /** Сегодня — текущее время (Вьетнам); прошлый день — полдень, без выдуманного времени. */
    private function txDateTime(string $ymd): string
    {
        $now = ($this->clock)();
        return $this->localDate($now, 'Y-m-d') === $ymd ? $this->localDate($now, 'Y-m-d H:i:s') : $ymd . ' 12:00:00';
    }

    private function localDate(int $ts, string $fmt): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(AiBotConfig::TZ))->format($fmt);
    }

    private static function recordLine(array $r): string
    {
        $head = self::h((string) $r['comment']) . ' — ' . PayoutParser::fmt((int) $r['amount']);
        $ids = implode(', ', array_map(static fn($t) => '#' . (int) $t, (array) ($r['tx_ids'] ?? [])));
        return match ((string) $r['status']) {
            'done' => '✅ ' . $head . ($ids !== '' ? ' → ' . $ids : ''),
            'dup' => '⚠️ ' . $head . ' — возможный дубль: ' . self::dupLine($r) . '. Повторить?',
            'skipped' => '↩️ ' . $head . ' — не внесено (дубль: ' . self::dupLine($r) . ')',
            'failed' => '❌ ' . $head . ' — ' . self::h((string) ($r['error'] ?? 'ошибка')),
            'sending' => '⏳ ' . $head . ' — исход неизвестен, проверится при повторе',
            'unverified' => '❓ ' . $head . ' — результат не определён, нужна сверка (не внесено повторно). В Poster есть похожие: ' . self::dupLine(['dup_of' => $r['candidates'] ?? []]),
            default => '• ' . $head,
        };
    }

    /** «#id от дд.мм.гггг, сумма, «комментарий»» исходных транзакций Poster. */
    private static function dupLine(array $r): string
    {
        $out = [];
        foreach ((array) ($r['dup_of'] ?? []) as $t) {
            $d = (string) ($t['date'] ?? '');
            $out[] = '#' . (int) ($t['id'] ?? 0)
                . ($d !== '' ? ' от ' . self::dm(substr($d, 0, 10), true) : '')
                . ', ' . PayoutParser::fmt((int) ($t['amount'] ?? 0))
                . ', «' . self::h(mb_substr((string) ($t['comment'] ?? ''), 0, 80)) . '»';
        }
        return $out === [] ? '—' : implode('; ', $out);
    }

    private static function statusLabel(string $s): string
    {
        return match ($s) {
            'draft' => 'черновик',
            'executing' => 'вносится',
            'done' => 'внесён',
            'partial' => 'внесён частично',
            'review' => 'ждёт решения по дублям',
            'reconcile' => 'нужна сверка',
            'cancelled' => 'отменён',
            default => $s,
        };
    }

    private static function txId(mixed $resp): ?int
    {
        if (is_int($resp) || (is_string($resp) && ctype_digit($resp))) {
            return (int) $resp > 0 ? (int) $resp : null;
        }
        if (is_array($resp)) {
            foreach (['transaction_id', 'id', 0, 'response'] as $k) {
                if (array_key_exists($k, $resp)) {
                    $v = self::txId($resp[$k]);
                    if ($v !== null) {
                        return $v;
                    }
                }
            }
        }
        return null;
    }

    private static function dm(string $ymd, bool $year = false): string
    {
        $p = explode('-', $ymd);
        return count($p) === 3 ? $p[2] . '.' . $p[1] . ($year ? '.' . $p[0] : '') : $ymd;
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function enc(array $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function dec(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $v = json_decode($json, true);
        return is_array($v) ? $v : [];
    }
}
