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
 *     та же сумма): совпавшее помечается «уже внесено (#id)» и пропускается.
 */
final class FinanceDraftService
{
    public const INTENT = 'investor_dividends';

    public const SPLIT_PERSON = 'person';
    public const SPLIT_PARTS = 'parts';

    private const DUP_WINDOW_DAYS = 3;
    private const DATE_PICK_DAYS = 6;

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

            $this->drafts->update($draftId, ['status' => 'executing', 'poster_tx_ids_json' => self::enc($records), 'error' => null]);

            $datetime = $this->txDateTime($date);
            foreach ($records as $i => $rec) {
                if (in_array($rec['status'], ['done', 'dup'], true)) {
                    continue;
                }
                // «sending» фиксируется ДО вызова: если процесс умрёт посреди, при
                // повторе запись не считается внесённой, но сверка с Poster выше
                // поймает её, если Poster транзакцию всё-таки создал.
                $records[$i]['status'] = 'sending';
                $this->drafts->update($draftId, ['poster_tx_ids_json' => self::enc($records)]);
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
            $final = $failed > 0 ? 'partial' : 'done';
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
                'message' => $final === 'done' ? 'Готово' : 'Часть записей не внесена — можно повторить',
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
        $pending = array_filter($records, static fn(array $r) => !in_array($r['status'], ['done', 'dup'], true));
        if ($pending === []) {
            return $records;
        }
        $d = new \DateTimeImmutable($date, new \DateTimeZone(AiBotConfig::TZ));
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
        }
        $pool = []; // tx_id → сумма VND
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
            // finance.getTransactions отдаёт сумму в копейках (×100), расход — со знаком «−».
            $pool[$tid] = (int) round(abs((float) ($t['amount'] ?? 0)) / 100);
        }

        foreach ($records as $i => $r) {
            if (in_array($r['status'], ['done', 'dup'], true)) {
                continue;
            }
            $hit = array_search((int) $r['amount'], $pool, true);
            if ($hit !== false) {
                unset($pool[$hit]);
                $records[$i]['status'] = 'dup';
                $records[$i]['tx_ids'] = [(int) $hit];
                continue;
            }
            $parts = (array) ($r['parts'] ?? []);
            if (count($parts) > 1) {
                $try = $pool;
                $ids = [];
                foreach ($parts as $amt) {
                    $h = array_search((int) $amt, $try, true);
                    if ($h === false) {
                        $ids = [];
                        break;
                    }
                    unset($try[$h]);
                    $ids[] = (int) $h;
                }
                if ($ids !== []) {
                    $pool = $try;
                    $records[$i]['status'] = 'dup';
                    $records[$i]['tx_ids'] = $ids;
                }
            }
        }
        return $records;
    }

    private function keyboard(array $draft, array $people): array
    {
        $id = (int) $draft['id'];
        $status = (string) $draft['status'];
        if ($status === 'partial') {
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
            'dup' => '↩️ ' . $head . ' — уже внесено (' . $ids . ')',
            'failed' => '❌ ' . $head . ' — ' . self::h((string) ($r['error'] ?? 'ошибка')),
            'sending' => '⏳ ' . $head . ' — исход неизвестен, проверится при повторе',
            default => '• ' . $head,
        };
    }

    private static function statusLabel(string $s): string
    {
        return match ($s) {
            'draft' => 'черновик',
            'executing' => 'вносится',
            'done' => 'внесён',
            'partial' => 'внесён частично',
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
