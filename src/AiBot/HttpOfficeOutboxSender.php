<?php

declare(strict_types=1);

namespace App\AiBot;

use Psr\Log\LoggerInterface;

/**
 * Доставка tg_office_outbox в офисный API (create -> POST /tasks, answer -> POST /tasks/{id}/answers).
 *
 * Тело запроса строится детерминированно из outbox.payload_json (без null-ключей), поэтому повтор
 * после сетевого сбоя шлёт тот же payload. Токен нигде не пишется: в last_error попадают только
 * HTTP-статус и код ошибки Vera, свободный текст ответа отбрасывается.
 */
final class HttpOfficeOutboxSender implements OfficeOutboxSenderInterface
{
    private const MAX_ATTEMPTS = 40;
    private const BACKOFF_BASE = 30;
    private const BACKOFF_MAX = 3600;
    private const REFRESH_PAGES = 3;
    private const DEFER_MAX_AGE = 86400;

    private bool $halted = false;
    /** @var \Closure(): int */
    private \Closure $clock;

    /** @param (\Closure(array<string,mixed>, string): void)|null $notify сообщение в чат задачи (task row, text) */
    public function __construct(
        private readonly OfficeTaskRepositoryInterface $repo,
        private readonly OfficeApiClient $api,
        private readonly LoggerInterface $logger,
        private readonly ?\Closure $notify = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function isHalted(): bool
    {
        return $this->halted;
    }

    public function send(array $outboxRow): bool
    {
        if ($this->halted) {
            return false;
        }
        $key = (string) $outboxRow['idempotency_key'];
        $payload = json_decode((string) ($outboxRow['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            return $this->fail($outboxRow, null, 'bad_payload', null);
        }
        try {
            return match ((string) ($outboxRow['kind'] ?? '')) {
                'create' => $this->create($outboxRow, $payload),
                'answer' => $this->answer($outboxRow, $payload),
                default => $this->fail($outboxRow, null, 'unknown_kind', null),
            };
        } catch (OfficeTransportException $e) {
            $this->logger->warning('aibot.office.send.network', ['key' => $key, 'err' => $e->getMessage()]);
            $this->retryLater($outboxRow, null, 'network');
            return false;
        }
    }

    /**
     * Канонический create-запрос: только присутствующие ключи, порядок фиксирован.
     *
     * @param array<string,mixed> $p payload_json строки outbox
     * @return array<string,mixed>
     */
    public static function buildCreate(array $p): array
    {
        $chat = (string) ($p['chat_id'] ?? '');
        $msg = (int) ($p['origin_message_id'] ?? 0);
        $text = trim((string) ($p['text'] ?? ''));
        $thread = isset($p['thread_id']) && (int) $p['thread_id'] > 0 ? (int) $p['thread_id'] : null;

        $origin = ['channel' => 'telegram', 'chat' => $chat, 'message' => $msg, 'from_id' => (int) ($p['origin_user_id'] ?? 0)];
        $recipient = ['channel' => 'telegram', 'chat' => $chat, 'reply_to' => $msg];
        if ($thread !== null) {
            $origin['thread'] = $thread;
            $recipient['thread'] = $thread;
        }
        $req = [
            'client_task_id' => 'tg-' . $chat . '-' . $msg,
            'title' => trim(mb_substr($text, 0, 200)),
            'body' => mb_substr($text, 0, 8000),
            'origin' => $origin,
            'result_recipient' => $recipient,
        ];
        $reply = $p['context']['reply_to'] ?? null;
        if (is_array($reply)) {
            $r = ['id' => (int) ($reply['message_id'] ?? 0)];
            if (!empty($reply['user_id'])) {
                $r['user'] = (int) $reply['user_id'];
            }
            $t = (string) ($reply['text'] ?? '');
            if ($t !== '') {
                $r['text'] = $t;
            }
            // Vera принимает в context только строки/числа: вложения цитаты — список типов строкой.
            $types = array_values(array_filter(array_map(
                static fn($m) => is_array($m) ? (string) ($m['type'] ?? '') : '',
                is_array($reply['media'] ?? null) ? $reply['media'] : []
            )));
            if ($types !== []) {
                $r['media'] = implode(',', $types);
            }
            if ($r['id'] > 0) {
                $req['context'] = ['reply_to' => $r];
            }
        }
        $atts = [];
        foreach (array_slice(is_array($p['media'] ?? null) ? $p['media'] : [], 0, 10) as $m) {
            if (!is_array($m) || (string) ($m['file_id'] ?? '') === '') {
                continue;
            }
            $a = ['type' => (string) ($m['type'] ?? 'document'), 'file_id' => (string) $m['file_id']];
            if ((string) ($m['file_unique_id'] ?? '') !== '') {
                $a['file_unique_id'] = (string) $m['file_unique_id'];
            }
            if ((string) ($m['mime_type'] ?? '') !== '') {
                $a['mime'] = (string) $m['mime_type'];
            }
            foreach (['file_size' => 'size', 'duration' => 'duration', 'width' => 'w', 'height' => 'h'] as $from => $to) {
                if (isset($m[$from]) && (int) $m[$from] >= 0) {
                    $a[$to] = (int) $m[$from];
                }
            }
            $atts[] = $a; // url / url_expires_at намеренно не шлём
        }
        if ($atts !== []) {
            $req['attachments'] = $atts;
        }
        $req['causation_id'] = (string) ($p['update_id'] ?? '');
        if ($req['causation_id'] === '') {
            unset($req['causation_id']);
        }
        return $req;
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $p */
    private function create(array $row, array $p): bool
    {
        $resp = $this->api->createTask(self::buildCreate($p));
        if ($resp->ok() && is_string($resp->data['task_id'] ?? null) && $resp->data['task_id'] !== '') {
            $taskKey = (string) $row['task_key'];
            $local = $this->repo->findTaskByKey($taskKey);
            $fields = ['office_task_id' => $resp->data['task_id'], 'office_revision' => (int) ($resp->data['revision'] ?? 0)];
            if ($local !== null && (string) $local['status'] === OfficeTaskService::STATUS_QUEUED) {
                $fields['status'] = 'waiting_executor';
            }
            $this->repo->updateTask($taskKey, $fields);
            $this->markSent($row);
            return true;
        }
        return $this->failOrRetry($row, $resp, 'не удалось отправить задачу в офис');
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $p */
    private function answer(array $row, array $p): bool
    {
        $taskKey = (string) $row['task_key'];
        $task = $this->repo->findTaskByKey($taskKey);
        if ($task === null) {
            return $this->fail($row, null, 'task_missing', null);
        }
        $officeId = (string) ($task['office_task_id'] ?? '');
        if ($officeId === '') {
            $this->defer($row, 30, 'waiting_create'); // create ещё не доставлен: ждём, попытку не тратим
            return false;
        }
        $qid = (string) ($p['question_id'] ?? '');
        if ($qid === '' || !ctype_digit($qid)) {
            return $this->fail($row, $task, 'bad_question_id', null);
        }
        $revision = isset($task['office_revision']) ? (int) $task['office_revision'] : null;
        if ($revision === null) {
            $revision = $this->refreshRevision($taskKey, $officeId, -1);
            if ($revision === null) {
                $this->defer($row, 60, 'revision_unknown');
                return false;
            }
        }
        $build = static fn(int $rev): array => [
            'question_id' => (int) $qid,
            'text' => mb_substr(trim((string) ($p['text'] ?? '')), 0, 8000),
            'expected_revision' => $rev,
            'idempotency_key' => (string) $row['idempotency_key'],
        ];
        $resp = $this->api->answer($officeId, $build($revision));
        if ($resp->status === 409 && $resp->errorCode() === 'revision_mismatch') {
            $fresh = $this->refreshRevision($taskKey, $officeId, $revision);
            if ($fresh === null) {
                $this->defer($row, 60, 'revision_mismatch'); // попытку не жжём: ждём, пока поллер обновит ревизию
                return false;
            }
            $this->repo->updateTask($taskKey, ['office_revision' => $fresh]);
            $resp = $this->api->answer($officeId, $build($fresh)); // один повтор с тем же ключом
            if ($resp->status === 409 && $resp->errorCode() === 'revision_mismatch') {
                $this->retryLater($row, $resp, 'revision_mismatch');
                return false;
            }
        }
        if ($resp->ok()) {
            $fields = ['office_revision' => (int) ($resp->data['revision'] ?? $revision)];
            if ((string) $task['status'] === OfficeTaskService::STATUS_WAITING_OWNER) {
                $fields['status'] = 'waiting_executor';
            }
            $this->repo->updateTask($taskKey, $fields);
            $this->markSent($row);
            return true;
        }
        if ($resp->status === 404 && (string) $task['status'] === OfficeTaskService::STATUS_WAITING_OWNER) {
            $this->repo->updateTask($taskKey, ['status' => 'waiting_executor']); // вопрос закрыт: не ждём ответа
        }
        $text = $resp->status === 404 ? 'вопрос уже закрыт' : 'не удалось отправить ответ в офис';
        return $this->failOrRetry($row, $resp, $text, $task, taskFailed: false);
    }

    /**
     * Свежая ревизия: сначала то, что поллер уже записал в задачу; иначе узкий список
     * (updated_since = сутки назад, до REFRESH_PAGES страниц). null - пока не нашли.
     */
    private function refreshRevision(string $taskKey, string $officeId, int $stale): ?int
    {
        $local = $this->repo->findTaskByKey($taskKey);
        if ($local !== null && isset($local['office_revision']) && (int) $local['office_revision'] !== $stale) {
            return (int) $local['office_revision'];
        }
        $since = gmdate('Y-m-d\TH:i:s', ($this->clock)() - 86400);
        $cursor = null;
        for ($i = 0; $i < self::REFRESH_PAGES; $i++) {
            $r = $this->api->listTasks($since, $cursor, 100);
            if (!$r->ok()) {
                return null;
            }
            foreach ((array) ($r->data['tasks'] ?? []) as $t) {
                if (is_array($t) && ($t['task_id'] ?? null) === $officeId) {
                    return (int) ($t['revision'] ?? 0);
                }
            }
            $cursor = is_string($r->data['next_cursor'] ?? null) ? $r->data['next_cursor'] : null;
            if ($cursor === null) {
                return null;
            }
        }
        return null;
    }

    /** Отложить строку без траты попытки. @param array<string,mixed> $row */
    private function defer(array $row, int $seconds, string $reason): void
    {
        // created_ts = UNIX_TIMESTAMP(created_at) из SQL: без сдвига часовых поясов PHP/MySQL
        if (isset($row['created_ts']) && ($this->clock)() - (int) $row['created_ts'] > self::DEFER_MAX_AGE) {
            $this->fail($row, $this->repo->findTaskByKey((string) $row['task_key']), $reason . ':deferred_too_long', 'не удалось отправить в офис (слишком долго в очереди)');
            return;
        }
        $this->repo->updateOutbox((string) $row['idempotency_key'], [
            'next_attempt_at' => date('Y-m-d H:i:s', ($this->clock)() + $seconds), 'last_error' => $reason,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $task
     */
    private function failOrRetry(array $row, OfficeApiResponse $r, string $humanText, ?array $task = null, bool $taskFailed = true): bool
    {
        $s = $r->status;
        if ($s === 401 || $s === 403) {
            $this->halted = true;
            $this->logger->error('aibot.office.auth_failed', ['status' => $s, 'hint' => 'check OFFICE_SERVICE_TOKEN']);
            return false; // строка остаётся pending, попытка не тратится
        }
        if ($s === 429 || $s === 503 || $s >= 500 || $s === 0) {
            $this->retryLater($row, $r, 'http_' . $s);
            return false;
        }
        // 400/404/409/422 и прочее — повтор тем же телом бессмыслен.
        $task ??= $this->repo->findTaskByKey((string) $row['task_key']);
        return $this->fail($row, $task, 'http_' . $s . ($r->errorCode() !== '' ? ':' . $r->errorCode() : ''), $humanText, $taskFailed);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $task
     */
    private function fail(array $row, ?array $task, string $reason, ?string $humanText, bool $taskFailed = true): bool
    {
        $this->repo->updateOutbox((string) $row['idempotency_key'], [
            'status' => 'failed', 'attempts' => (int) ($row['attempts'] ?? 0) + 1, 'last_error' => $reason, 'next_attempt_at' => null,
        ]);
        $this->logger->error('aibot.office.send.failed', ['key' => $row['idempotency_key'], 'kind' => $row['kind'] ?? null, 'reason' => $reason]);
        if ($task !== null && $taskFailed) {
            $this->repo->updateTask((string) $task['task_key'], ['status' => 'failed']);
        }
        if ($task !== null && $humanText !== null && $this->notify !== null) {
            try {
                ($this->notify)($task, '⚠️ Задача #' . ($task['id'] ?? $task['task_key']) . ': ' . $humanText);
            } catch (\Throwable $e) {
                $this->logger->warning('aibot.office.notify_failed', ['err' => get_class($e)]);
            }
        }
        return false;
    }

    /** @param array<string,mixed> $row */
    private function retryLater(array $row, ?OfficeApiResponse $r, string $reason): void
    {
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->fail($row, $this->repo->findTaskByKey((string) $row['task_key']), $reason . ':attempts_exhausted', 'не удалось отправить в офис (исчерпаны попытки)');
            return;
        }
        $delay = $r?->retryAfter ?? min(self::BACKOFF_MAX, self::BACKOFF_BASE * (2 ** min($attempts, 7)));
        $this->repo->updateOutbox((string) $row['idempotency_key'], [
            'attempts' => $attempts,
            'next_attempt_at' => date('Y-m-d H:i:s', ($this->clock)() + $delay),
            'last_error' => $reason,
        ]);
    }

    /** @param array<string,mixed> $row */
    private function markSent(array $row): void
    {
        $this->repo->updateOutbox((string) $row['idempotency_key'], [
            'status' => 'sent', 'attempts' => (int) ($row['attempts'] ?? 0) + 1, 'last_error' => null, 'next_attempt_at' => null,
        ]);
    }
}
