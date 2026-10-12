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
    public const DIRECT_INTENT = 'direct_expense';

    public const SPLIT_PERSON = 'person';
    public const SPLIT_PARTS = 'parts';

    private const RECONCILE_WINDOW_DAYS = 3;
    private const EXEC_STALE_SEC = 120;
    public const MAX_PEOPLE = 30;

    /** Записи, которые execute() не трогает: внесены, ждут решения по дублю, пропущены владельцем, исход не определён (нужна сверка). */
    private const SETTLED = ['done', 'dup', 'skipped', 'unverified'];

    /** @var array<int,string>|null */
    private ?array $accountNames = null;

    /** @var array<int,string>|null */
    private ?array $categoryNames = null;

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
        /** Право проверяется ещё раз под локом, прямо перед записью/решением. */
        private readonly ?FinanceAuthorizerInterface $authorizer = null,
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
                'tx_date' => $sourceDate > 0 ? $this->localDate($sourceDate, 'Y-m-d') : null,
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

    /** @param array{type:int,amount:int,account_hint:string,account_to_hint:string,comment:string} $transaction */
    public function createOrGetDirect(string $chatId, int $sourceMsgId, int $triggerMsgId, int $initiatorTgId, int $sourceDate, array $transaction, callable $sendCard): array
    {
        return $this->lock->synchronized('aibot_src_' . $chatId . '_' . $sourceMsgId, 5, function () use ($chatId, $sourceMsgId, $triggerMsgId, $initiatorTgId, $sourceDate, $transaction, $sendCard) {
            $existing = $this->drafts->findBySource($chatId, $sourceMsgId, self::DIRECT_INTENT);
            if ($existing !== null) {
                return ['draft' => $existing, 'created' => false];
            }
            $accountId = $this->matchAccountHint($transaction['account_hint']);
            $accountToId = $transaction['type'] === 3 ? $this->matchAccountHint($transaction['account_to_hint']) : null;
            $id = $this->drafts->insert([
                'chat_id' => $chatId,
                'source_msg_id' => $sourceMsgId,
                'trigger_msg_id' => $triggerMsgId,
                'initiator_tg_id' => $initiatorTgId,
                'intent' => self::DIRECT_INTENT,
                'rows_json' => self::enc([
                    'people' => [PayoutParser::row($transaction['comment'], $transaction['amount'], [])],
                    'period' => null,
                    'errors' => [],
                    'source_date' => $sourceDate,
                    'direct' => ['type' => $transaction['type'], 'amount' => $transaction['amount'],
                        'account_hint' => $transaction['account_hint'], 'account_to_hint' => $transaction['account_to_hint'],
                        'account_to_id' => $accountToId, 'comment' => $transaction['comment'], 'category_id' => null],
                ]),
                'account_id' => $accountId,
                'split_mode' => self::SPLIT_PERSON,
                'tx_date' => $sourceDate > 0 ? $this->localDate($sourceDate, 'Y-m-d') : null,
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

    /**
     * Новое поколение карточки: подтверждения записи принимаются только с
     * кнопок текущего поколения. Вызывается при КАЖДОЙ отправке/правке карточки,
     * поэтому любое старое нажатие (из очереди Telegram, с прошлой карточки,
     * повторная доставка) не совпадёт и ничего не запишет.
     */
    public function renderFresh(array $draft): array
    {
        $id = (int) ($draft['id'] ?? 0);
        if ($id > 0) {
            // Под тем же локом, что execute/decideDuplicate: смена поколения атомарна
            // относительно проверки подтверждения.
            $draft = $this->lock->synchronized('aibot_fd_' . $id, 10, function () use ($id) {
                $this->rotateNonce($id);
                return (array) $this->drafts->get($id);
            });
        }
        return $this->render($draft);
    }

    /** Новое поколение карточки. Вызывать только под локом aibot_fd_<id>. */
    private function rotateNonce(int $id): void
    {
        $this->drafts->update($id, ['confirm_nonce' => bin2hex(random_bytes(4)), 'confirm_nonce_at' => ($this->clock)()]);
    }

    /** Нажатие подтверждения — с кнопки текущего поколения, выданного не раньше отсечки. */
    public static function confirmIsFresh(array $draft, string $nonce, int $notBefore): bool
    {
        $cur = (string) ($draft['confirm_nonce'] ?? '');
        return $cur !== '' && $nonce !== '' && hash_equals($cur, $nonce)
            && (int) ($draft['confirm_nonce_at'] ?? 0) >= $notBefore;
    }

    public function get(int $id): ?array
    {
        return $this->drafts->get($id);
    }

    /** Recheck unresolved duplicate and pending rows without sending anything to Poster. */
    public function refreshDuplicateCandidates(int $id): array
    {
        return $this->lock->synchronized('aibot_fd_' . $id, 10, function () use ($id): array {
            $draft = $this->drafts->get($id);
            if ($draft === null) {
                throw new \RuntimeException('Черновик не найден');
            }
            if (!in_array((string) $draft['status'], ['review', 'partial'], true)) {
                return $draft;
            }
            $records = self::dec($draft['poster_tx_ids_json'] ?? null);
            $hasOpenRows = array_filter($records, static fn(array $r) => in_array(($r['status'] ?? ''), ['dup', 'pending'], true)) !== [];
            if (!$hasOpenRows) {
                return $draft;
            }

            // Probe only duplicate and pending rows. Preserve completed and
            // uncertain rows, including their original transaction IDs.
            $probe = $records;
            foreach ($probe as &$row) {
                if (in_array(($row['status'] ?? ''), ['dup', 'pending'], true)) {
                    $row['status'] = 'pending';
                    unset($row['dup_of']);
                } else {
                    $row['status'] = 'done';
                }
            }
            unset($row);
            $rechecked = $this->markDuplicates($probe, (int) $draft['account_id'], (string) $draft['tx_date'], self::categoryFor($draft), self::typeFor($draft), self::accountToFor($draft));
            foreach ($records as $i => &$row) {
                if (!in_array(($row['status'] ?? ''), ['dup', 'pending'], true)) {
                    continue;
                }
                $row['status'] = $rechecked[$i]['status'];
                $row['tx_ids'] = $rechecked[$i]['tx_ids'];
                if ($row['status'] === 'dup') {
                    $row['dup_of'] = $rechecked[$i]['dup_of'];
                } else {
                    unset($row['dup_of']);
                }
            }
            unset($row);
            $has = static fn(string $status): bool => array_filter($records, static fn(array $r) => $r['status'] === $status) !== [];
            $status = ($has('pending') || $has('failed') || $has('sending')) ? 'partial'
                : ($has('dup') ? 'review' : ($has('unverified') ? 'reconcile' : 'done'));
            $sourceDate = (int) (self::dec($draft['rows_json'] ?? null)['source_date'] ?? 0);
            $txDate = $sourceDate > 0 ? $this->localDate($sourceDate, 'Y-m-d') : null;
            if ($records !== self::dec($draft['poster_tx_ids_json'] ?? null) || $status !== $draft['status'] || $txDate !== $draft['tx_date']) {
                $this->drafts->update($id, [
                    'status' => $status,
                    'tx_date' => $txDate,
                    'poster_tx_ids_json' => self::enc($records),
                    'confirm_nonce' => bin2hex(random_bytes(4)),
                    'confirm_nonce_at' => ($this->clock)(),
                ]);
                return (array) $this->drafts->get($id);
            }
            return $draft;
        });
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
        // Изменение реквизитов и смена поколения — одной операцией под локом: старое
        // «Внести» не может пройти проверку и исполниться уже с новыми реквизитами.
        return $this->lock->synchronized('aibot_fd_' . $draftId, 10, function () use ($draftId, $action, $arg) {
            $toast = $this->applyChoiceLocked($draftId, $action, $arg);
            if ($this->drafts->get($draftId) !== null) {
                $this->rotateNonce($draftId);
            }
            return $toast;
        });
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
            case 'cat':
                if ((string) $draft['intent'] !== self::DIRECT_INTENT || !isset($this->categoryChoices()[(int) $arg])) {
                    return 'Категория недоступна';
                }
                $data = self::dec($draft['rows_json'] ?? null);
                $data['direct']['category_id'] = (int) $arg;
                $this->drafts->update($draftId, ['rows_json' => self::enc($data)]);
                return 'Категория: ' . $this->categoryChoices()[(int) $arg];
            case 'to':
                if (self::typeFor($draft) !== 3 || !isset($this->accountChoices()[(int) $arg])) {
                    return 'Счёт назначения недоступен';
                }
                $data = self::dec($draft['rows_json'] ?? null);
                $data['direct']['account_to_id'] = (int) $arg;
                $this->drafts->update($draftId, ['rows_json' => self::enc($data)]);
                return 'Счёт назначения: ' . $this->accountChoices()[(int) $arg];
            case 'date':
                return 'Дата и время берутся из исходного сообщения';
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
     * $expectedNonce — поколение карточки с нажатой кнопки: сверяется ПОД локом с
     * перечитанным черновиком (null — без проверки, только для внутренних вызовов
     * и тестов). Совпало — поколение сразу погашается (одноразовое подтверждение).
     *
     * @return array{draft:array<string,mixed>, message:string, stale?:bool}
     */
    public function execute(int $draftId, int $actorTgId, ?string $expectedNonce = null, int $notBefore = 0): array
    {
        return $this->lock->synchronized('aibot_fd_' . $draftId, 10, function () use ($draftId, $actorTgId, $expectedNonce, $notBefore) {
            $draft = $this->drafts->get($draftId);
            if ($draft === null) {
                return ['draft' => [], 'message' => 'Черновик не найден'];
            }
            // Отзыв права действует сразу, в т.ч. для уже показанной карточки.
            if ($this->authorizer !== null && !$this->authorizer->allows($actorTgId)) {
                return ['draft' => $draft, 'message' => 'Нет доступа', 'denied' => true];
            }
            if ($expectedNonce !== null) {
                if (!self::confirmIsFresh($draft, $expectedNonce, $notBefore)) {
                    return ['draft' => $draft, 'message' => 'Кнопка устарела — подтвердите заново', 'stale' => true];
                }
                $this->rotateNonce($draftId);
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
            } elseif (array_filter($records, static fn(array $r) => !in_array($r['status'], self::SETTLED, true)) === []) {
                // Отправлять нечего: без вызова Poster только фиксируем итоговый статус
                // (например, процесс умер после последней записи, черновик застрял в executing).
                $final = self::finalStatus($records);
                if ($final !== (string) $draft['status']) {
                    $this->drafts->update($draftId, ['status' => $final]);
                }
                return ['draft' => (array) $this->drafts->get($draftId), 'message' => 'Отправлять нечего — ' . self::statusLabel($final)];
            }
            $accountId = (int) $draft['account_id'];
            $sourceDate = (int) (self::dec($draft['rows_json'] ?? null)['source_date'] ?? 0);
            $date = $this->localDate($sourceDate, 'Y-m-d');

            // Сверка с Poster ДО записи. Не смогли проверить — не пишем (fail closed).
            try {
                $records = $this->markDuplicates($records, $accountId, $date, self::categoryFor($draft), self::typeFor($draft), self::accountToFor($draft));
            } catch (\Throwable $e) {
                $this->drafts->update($draftId, ['error' => 'Проверка дублей в Poster не удалась: ' . mb_substr($e->getMessage(), 0, 300)]);
                return ['draft' => (array) $this->drafts->get($draftId), 'message' => 'Не смог проверить дубли в Poster — ничего не внесено'];
            }

            $this->drafts->update($draftId, ['status' => 'executing', 'poster_tx_ids_json' => self::enc($records), 'error' => null, 'heartbeat_at' => ($this->clock)()]);

            $datetime = $this->localDate($sourceDate, 'Y-m-d H:i:s');
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
                        'type' => self::typeFor($draft),
                        'amount' => (int) $rec['amount'],
                        'date' => $datetime,
                        'comment' => (string) $rec['comment'],
                        'category_id' => self::categoryFor($draft),
                        'account_from' => self::typeFor($draft) === 1 ? 0 : $accountId,
                        'account_to' => self::typeFor($draft) === 1 ? $accountId : self::accountToFor($draft),
                    ], new Actor('tg:' . $actorTgId, 'tgdraft:' . $draftId . ':' . $i));
                    // Успех — только при подтверждении с ID транзакции (Poster отвечает
                    // {"response": <id>} или [<id>]). Валидный JSON без ID ({}, [],
                    // {"response":null}) PosterAPI не считает ошибкой — для нас это
                    // неизвестный исход: сверка, без повторной отправки.
                    $tid = self::txId($res['response'] ?? null);
                    if ($tid === null) {
                        $records[$i]['status'] = 'failed';
                        $records[$i]['outcome'] = 'unknown';
                        $records[$i]['error'] = 'Poster ответил без ID транзакции';
                    } else {
                        $records[$i]['status'] = 'done';
                        $records[$i]['tx_ids'] = [$tid];
                        unset($records[$i]['error']);
                    }
                } catch (\Throwable $e) {
                    $records[$i]['status'] = 'failed';
                    $records[$i]['error'] = mb_substr($e->getMessage(), 0, 200);
                    // rejected — доказанный отказ (запрос не ушёл / Poster вернул явную ошибку):
                    // повтор допустим. unknown — исход не установлен: повтор только после сверки.
                    $records[$i]['outcome'] = self::isProvenRejection($e) ? 'rejected' : 'unknown';
                }
                $this->drafts->update($draftId, ['poster_tx_ids_json' => self::enc($records)]);
            }

            // Неизвестный исход — сразу «нужна сверка» (с подсказкой из Poster, если она есть):
            // кнопка «Повторить незавершённые» такую запись не пошлёт.
            if (array_filter($records, static fn(array $r) => $r['status'] === 'failed' && ($r['outcome'] ?? 'unknown') !== 'rejected') !== []) {
                try {
                    $records = $this->markDuplicates($records, $accountId, $date, self::categoryFor($draft), self::typeFor($draft), self::accountToFor($draft));
                } catch (\Throwable) {
                    foreach ($records as $k => $r) {
                        if ($r['status'] === 'failed' && ($r['outcome'] ?? 'unknown') !== 'rejected') {
                            $records[$k]['status'] = 'unverified';
                            $records[$k]['candidates'] = [];
                        }
                    }
                }
            }
            $failed = count(array_filter($records, static fn(array $r) => $r['status'] === 'failed'));
            $open = count(array_filter($records, static fn(array $r) => $r['status'] === 'dup'));
            // Возможные дубли не вносятся — ждут явного решения владельца (review).
            $final = self::finalStatus($records);
            $this->drafts->update($draftId, ['status' => $final, 'poster_tx_ids_json' => self::enc($records)]);

            try {
                $this->audit->record('tg:' . $actorTgId, 'aibot.finance_draft.execute', [
                    'draft_id' => $draftId,
                    'chat_id' => $draft['chat_id'],
                    'source_msg_id' => (int) $draft['source_msg_id'],
                    'account_id' => $accountId,
                    'category_id' => self::categoryFor($draft),
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
        if ((string) ($draft['intent'] ?? '') === self::DIRECT_INTENT) {
            return $this->renderDirect($draft);
        }
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
        $L[] = 'Дата и время: ' . (!empty($data['source_date']) ? $this->localDate((int) $data['source_date'], 'd.m.Y H:i:s') : '— нет даты исходного сообщения —');

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
    /** @return array{text:string,keyboard:array} */
    private function renderDirect(array $draft): array
    {
        $data = self::dec($draft['rows_json'] ?? null);
        $direct = (array) ($data['direct'] ?? []);
        $type = self::typeFor($draft);
        $accountId = (int) ($draft['account_id'] ?? 0);
        $categoryId = self::categoryFor($draft);
        $lines = [
            '💳 <b>Финансовый черновик #' . (int) $draft['id'] . '</b>',
            'Источник: сообщение #' . (int) $draft['source_msg_id'],
            'Дата и время: ' . (!empty($data['source_date']) ? $this->localDate((int) $data['source_date'], 'd.m.Y H:i:s') : '—'),
            match ($type) { 1 => 'Доход: ', 2 => 'Расход: ', 3 => 'Перевод: ', default => 'Сумма: ' }
                . PayoutParser::fmt((int) ($direct['amount'] ?? 0)) . ' VND',
            ($type === 1 ? 'На счёт: ' : 'Со счёта: ')
                . self::h($this->accountChoices()[$accountId] ?? '— выберите —'),
            $type === 3
                ? 'На счёт: ' . self::h($this->accountChoices()[self::accountToFor($draft)] ?? '— выберите —')
                : 'Категория: ' . self::h($this->categoryChoices()[$categoryId] ?? '— выберите —'),
            'Комментарий: ' . self::h((string) ($direct['comment'] ?? '')),
        ];
        if ((string) $draft['status'] !== 'draft') {
            $lines[] = 'Статус: ' . self::statusLabel((string) $draft['status']);
            foreach (self::dec($draft['poster_tx_ids_json'] ?? null) as $record) {
                $lines[] = self::recordLine($record);
            }
        } else {
            $lines[] = $this->blocker($draft) ?? 'Проверьте поля и подтвердите запись';
        }
        return ['text' => implode("\n", $lines), 'keyboard' => $this->keyboard($draft, (array) ($data['people'] ?? []))];
    }

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
        if ((string) ($draft['intent'] ?? '') === self::DIRECT_INTENT) {
            if (self::typeFor($draft) === 3) {
                if (!isset($this->accountChoices()[self::accountToFor($draft)])
                    || self::accountToFor($draft) === (int) $draft['account_id']) {
                    return 'Выберите другой счёт назначения';
                }
            } elseif (!isset($this->categoryChoices()[self::categoryFor($draft)])) {
                return 'Выберите категорию';
            }
        }
        if ((int) ($data['source_date'] ?? 0) <= 0) {
            return 'Нет даты исходного сообщения';
        }
        return null;
    }

    // ─── внутреннее ────────────────────────────────────────────────────────

    /** @return array<int,string> */
    public function categoryChoices(): array
    {
        if ($this->categoryNames !== null) {
            return $this->categoryNames;
        }
        $out = [];
        try {
            foreach ($this->lookup->financeCategories() as $id => $category) {
                if ((int) $id > 0 && trim((string) ($category['name'] ?? '')) !== '') {
                    $out[(int) $id] = trim((string) $category['name']);
                }
            }
        } catch (\Throwable) {
            // No category choice is safer than a guessed Poster category.
        }
        return $this->categoryNames = $out;
    }

    private function matchAccountHint(string $hint): ?int
    {
        $hint = trim($hint);
        if ($hint === '') {
            return null;
        }
        $matches = array_filter($this->accountChoices(), static fn(string $name) =>
            mb_stripos($name, $hint, 0, 'UTF-8') !== false);
        return count($matches) === 1 ? (int) array_key_first($matches) : null;
    }

    private static function typeFor(array $draft): int
    {
        return (string) ($draft['intent'] ?? '') === self::DIRECT_INTENT
            ? (int) (self::dec($draft['rows_json'] ?? null)['direct']['type'] ?? 0) : 2;
    }

    private static function accountToFor(array $draft): int
    {
        return (int) (self::dec($draft['rows_json'] ?? null)['direct']['account_to_id'] ?? 0);
    }

    private static function categoryFor(array $draft): int
    {
        if ((string) ($draft['intent'] ?? '') !== self::DIRECT_INTENT) {
            return AiBotConfig::CATEGORY_ID;
        }
        return self::typeFor($draft) === 3 ? 0
            : (int) (self::dec($draft['rows_json'] ?? null)['direct']['category_id'] ?? 0);
    }

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
        if ((string) ($draft['intent'] ?? '') === self::DIRECT_INTENT) {
            $direct = (array) ($data['direct'] ?? []);
            $comment = (string) ($direct['comment'] ?? '');
            return [['name' => $comment, 'amount' => (int) ($direct['amount'] ?? 0), 'parts' => [],
                'comment' => $comment, 'status' => 'pending', 'tx_ids' => []]];
        }
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
    private function markDuplicates(array $records, int $accountId, string $date, int $categoryId, int $type = 2, int $accountToId = 0): array
    {
        $pending = array_filter($records, static fn(array $r) => !in_array($r['status'], self::SETTLED, true));
        if ($pending === []) {
            return $records;
        }
        $today = (new \DateTimeImmutable('@' . ($this->clock)()))
            ->setTimezone(new \DateTimeZone(AiBotConfig::TZ))->format('Y-m-d');
        if ($type === 3) {
            return $this->markTransferDuplicates($records, $accountId, $accountToId, $date, $today);
        }
        // Пагинации у finance.getTransactions в коде проекта нет (ExpenseService берёт
        // месяц одним вызовом; per_page/limit есть только у transactions./dash.getTransactions).
        // Обычные похожие записи ищем только за сегодняшний день в TZ бота.
        $rows = $this->poster->client()->request('finance.getTransactions', [
            'dateFrom' => str_replace('-', '', $today),
            'dateTo' => str_replace('-', '', $today),
            'account_id' => $accountId,
            'timezone' => 'client',
        ]);
        if (!is_array($rows)) {
            throw new \RuntimeException('неожиданный ответ finance.getTransactions');
        }

        // Ambiguous sends are reconciled around their intended transaction date.
        // This separate search never makes an uncertain record safe to resend.
        $uncertain = array_filter($records, static fn(array $r) =>
            $r['status'] === 'sending' || ($r['status'] === 'failed' && ($r['outcome'] ?? 'unknown') !== 'rejected'));
        if ($uncertain !== []) {
            $d = new \DateTimeImmutable($date, new \DateTimeZone(AiBotConfig::TZ));
            $reconcileRows = $this->poster->client()->request('finance.getTransactions', [
                'dateFrom' => $d->modify('-' . self::RECONCILE_WINDOW_DAYS . ' days')->format('Ymd'),
                'dateTo' => $d->modify('+' . self::RECONCILE_WINDOW_DAYS . ' days')->format('Ymd'),
                'account_id' => $accountId,
                'timezone' => 'client',
            ]);
            if (!is_array($reconcileRows)) {
                throw new \RuntimeException('неожиданный ответ finance.getTransactions');
            }
            $rows = array_merge($rows, $reconcileRows);
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
            if ($tid <= 0 || isset($own[$tid]) || (int) ($t['category_id'] ?? 0) !== $categoryId
                || ($type === 1 && (int) ($t['type'] ?? -1) !== 1)) {
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

        // Проход 1 — по ВСЕМУ пулу, до сопоставления дублей: иначе более ранняя строка
        // могла бы «забрать» кандидата и строка с неизвестным исходом ушла бы повторно.
        $claimed = [];
        foreach ($records as $i => $r) {
            $st = (string) $r['status'];
            if ($st !== 'sending' && !($st === 'failed' && ($r['outcome'] ?? 'unknown') !== 'rejected')) {
                continue;
            }
            // Отправка с неизвестным исходом (sending — упали после вызова, failed —
            // ошибка/таймаут, но Poster мог создать). Уникального признака операции в
            // комментарии нет, поэтому «свою» транзакцию однозначно узнать нельзя:
            // любая транзакция той же суммы и того же получателя (или с тем же
            // комментарием) делает исход неопределённым. Такая запись НЕ становится
            // done и НЕ отправляется повторно — владельцу сообщается «нужна сверка».
            // Исход попытки записи неизвестен (sending, таймаут, обрыв, ошибка без
            // явного отказа). Пустая выдача чтения ничего не доказывает: запись могла
            // создаться и ещё не попасть в getTransactions, или её комментарий изменили.
            // Поэтому — сверка всегда, кандидаты лишь подсказка владельцу.
            $cand = self::uncertainCandidates($pool, (int) $r['amount'], (string) $r['name'], (string) $r['comment']);
            $records[$i]['status'] = 'unverified';
            $records[$i]['candidates'] = array_map(static fn(int $id) => ['id' => $id] + $pool[$id], $cand);
            $claimed += array_flip($cand);
        }
        $pool = array_diff_key($pool, $claimed);
        $pool = array_filter($pool, static fn(array $t) => substr($t['date'], 0, 10) === $today);

        // Проход 2 — возможные дубли для остальных строк.
        foreach ($records as $i => $r) {
            $st = (string) $r['status'];
            if (in_array($st, self::SETTLED, true)) {
                continue;
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
    /** Treat either matching transfer leg as a possible duplicate; one leg is enough to block a new send. */
    private function markTransferDuplicates(array $records, int $fromId, int $toId, string $date, string $today): array
    {
        $uncertain = array_filter($records, static fn(array $r) => $r['status'] === 'sending'
            || ($r['status'] === 'failed' && ($r['outcome'] ?? 'unknown') !== 'rejected')) !== [];
        $day = new \DateTimeImmutable($date, new \DateTimeZone(AiBotConfig::TZ));
        $fromDate = $uncertain ? $day->modify('-' . self::RECONCILE_WINDOW_DAYS . ' days')->format('Ymd') : str_replace('-', '', $today);
        $toDate = $uncertain ? $day->modify('+' . self::RECONCILE_WINDOW_DAYS . ' days')->format('Ymd') : str_replace('-', '', $today);
        $pool = [];
        foreach ([$fromId => 0, $toId => 1] as $account => $wireType) {
            $rows = $this->poster->client()->request('finance.getTransactions', [
                'dateFrom' => $fromDate, 'dateTo' => $toDate, 'account_id' => $account, 'timezone' => 'client',
            ]);
            if (!is_array($rows)) {
                throw new \RuntimeException('Не удалось проверить переводы в Poster');
            }
            foreach ($rows as $row) {
                if (!is_array($row) || (int) ($row['type'] ?? -1) !== $wireType
                    || (int) ($row['account_id'] ?? 0) !== $account) {
                    continue;
                }
                $id = (int) ($row['transaction_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $pool[$account . ':' . $id] = [
                    'id' => $id, 'amount' => (int) round(abs((float) ($row['amount'] ?? 0)) / 100),
                    'comment' => trim((string) ($row['comment'] ?? '')), 'date' => (string) ($row['date'] ?? ''),
                ];
            }
        }
        foreach ($records as $i => $record) {
            if (in_array((string) $record['status'], self::SETTLED, true) || !empty($record['repeat_ok'])) {
                continue;
            }
            $candidates = array_values(array_filter($pool, static fn(array $row) =>
                $row['amount'] === (int) $record['amount']
                && self::normComment($row['comment']) === self::normComment((string) $record['comment'])
                && ($uncertain || substr($row['date'], 0, 10) === $today)));
            if ($record['status'] === 'sending'
                || ($record['status'] === 'failed' && ($record['outcome'] ?? 'unknown') !== 'rejected')) {
                $records[$i]['status'] = 'unverified';
                $records[$i]['candidates'] = $candidates;
            } elseif ($candidates !== []) {
                $records[$i]['status'] = 'dup';
                $records[$i]['tx_ids'] = [];
                $records[$i]['dup_of'] = $candidates;
            }
        }
        return $records;
    }

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
            // Poster comments are sometimes entered in Latin letters while
            // the source message uses Cyrillic (Стас / Stas). The account,
            // category, local day, and amount have already been checked.
            $latinName = self::latinize($name);
            $latinComment = self::latinize((string) $t['comment']);
            if ($latinName !== '' && preg_match('/(?<!\p{L})' . preg_quote($latinName, '/') . '(?!\p{L})/u', $latinComment)) {
                return (int) $tid;
            }
        }
        return null;
    }

    private static function latinize(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g',
        ]);
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
    /** Итог по записям: есть failed → partial; ждут решения по дублю → review; исход не определён → reconcile; иначе done. */
    private static function finalStatus(array $records): string
    {
        $has = static fn(string $st): bool => array_filter($records, static fn(array $r) => $r['status'] === $st) !== [];
        return $has('failed') ? 'partial' : ($has('dup') ? 'review' : ($has('unverified') ? 'reconcile' : 'done'));
    }

    /**
     * Доказанный отказ ДО создания записи: запрос не ушёл (валидация до вызова,
     * не удалось соединиться / разрешить имя) или Poster ответил явной ошибкой
     * (объект error в ответе). Всё прочее — таймаут, обрыв, пустой/битый ответ,
     * HTTP 5xx — исход неизвестен.
     */
    private static function isProvenRejection(\Throwable $e): bool
    {
        // Валидация до вызова Poster — запрос не уходил.
        if ($e instanceof \InvalidArgumentException) {
            return true;
        }
        // DomainException: доказательство только «лок занят» (тоже до вызова). Отказ
        // «Такая же транзакция только что создана» означает, что предыдущая попытка
        // как раз УСПЕЛА создать запись, — это не отказ, а неизвестный исход.
        if ($e instanceof \DomainException) {
            return str_starts_with($e->getMessage(), 'Операция уже выполняется');
        }
        // Каждое сообщение цепочки проверяется с начала строки: в params= внутри
        // текста ошибки может оказаться комментарий пользователя.
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $m = preg_replace('/^Poster: /u', '', $x->getMessage()) ?? '';
            if (preg_match('/^CURL Error: (Could not resolve host|Couldn\'t resolve host|Failed to connect|Couldn\'t connect|curl_init failed)/i', $m)) {
                return true;
            }
            // PosterAPI: «Poster API Error: <текст ошибки> (http=…)» — Poster вернул error.
            // «Poster API Error: http=…» (не-2xx) и «empty response» — не доказательство.
            if (preg_match('/^Poster API Error: (?!http=|empty response)\S/u', $m)) {
                return true;
            }
        }
        return false;
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
    public function decideDuplicate(int $draftId, int $index, string $decision, int $actorTgId, ?string $expectedNonce = null, int $notBefore = 0): array
    {
        return $this->lock->synchronized('aibot_fd_' . $draftId, 10, function () use ($draftId, $index, $decision, $actorTgId, $expectedNonce, $notBefore) {
            $draft = $this->drafts->get($draftId);
            if ($draft === null) {
                return ['Черновик не найден', false];
            }
            // Поколение сверяется под локом; не погашается — следом execute() с тем же
            // поколением вносит именно эту запись.
            if ($this->authorizer !== null && !$this->authorizer->allows($actorTgId)) {
                return ['Нет доступа', false, false, true];
            }
            if ($expectedNonce !== null && !self::confirmIsFresh($draft, $expectedNonce, $notBefore)) {
                return ['Кнопка устарела — подтвердите заново', false, true];
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
        // Поколение карточки — в каждой кнопке, ведущей к записи (fd_go / fd_rep / fd_skip).
        $nonce = (string) ($draft['confirm_nonce'] ?? '');
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
                    ['text' => '🔁 Повторить: ' . $label, 'callback_data' => 'fd_rep:' . $id . ':' . $i . ':' . $nonce],
                    ['text' => '✖️ Не вносить', 'callback_data' => 'fd_skip:' . $id . ':' . $i . ':' . $nonce],
                ];
            }
            return $kb;
        }
        if ($status === 'partial' || ($status === 'executing' && $this->isStale($draft))) {
            return [[['text' => '🔁 Повторить незавершённые', 'callback_data' => 'fd_go:' . $id . ':0:' . $nonce]]];
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

        if ((string) ($draft['intent'] ?? '') === self::DIRECT_INTENT) {
            $row = [];
            $choices = self::typeFor($draft) === 3 ? $this->accountChoices() : $this->categoryChoices();
            foreach ($choices as $choiceId => $name) {
                $mark = (self::typeFor($draft) === 3 ? self::accountToFor($draft) : self::categoryFor($draft)) === $choiceId ? '✅ ' : '';
                $row[] = ['text' => $mark . $name, 'callback_data' => 'fd_' . (self::typeFor($draft) === 3 ? 'to' : 'cat') . ':' . $id . ':' . $choiceId];
                if (count($row) === 2) {
                    $kb[] = $row;
                    $row = [];
                }
            }
            if ($row !== []) {
                $kb[] = $row;
            }
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
            $last[] = ['text' => '✅ Внести', 'callback_data' => 'fd_go:' . $id . ':0:' . $nonce];
        }
        $last[] = ['text' => '✖️ Отмена', 'callback_data' => 'fd_cancel:' . $id];
        $kb[] = $last;
        return $kb;
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
            'unverified' => '❓ ' . $head . ' — результат не определён, нужна сверка (не внесено повторно). '
                . (!empty($r['candidates'])
                    ? 'В Poster есть похожие: ' . self::dupLine(['dup_of' => $r['candidates']])
                    : 'Похожих в Poster пока не видно — проверьте вручную, запись могла создаться.'),
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
            'reconcile' => 'ждёт сверки',
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
