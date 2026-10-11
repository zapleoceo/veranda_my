<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\TelegramBotClient;
use Psr\Log\LoggerInterface;

/**
 * Cron-проход: (1) отправка due-строк outbox, (2) опрос своих задач в офисе и доставка в Telegram:
 * новые вопросы (идемпотентно), результат (один раз), затем POST /delivered {revision}.
 *
 * Курсор: время updated_at последней обработанной задачи (минус 1 с) в tg_office_state.
 * Если задачу обработать не удалось, курсор не уходит дальше её updated_at: повтор на следующем проходе.
 */
final class OfficePoller implements OfficePollerInterface
{
    public const STATE_SINCE = 'poll_since';
    private const BATCH = 20;
    private const PAGE = 100;
    private const MAX_PAGES = 20;
    private const RESULT_MAX = 3800;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly OfficeTaskRepositoryInterface $repo,
        private readonly OfficeOutboxSenderInterface $sender,
        private readonly OfficeApiClient $api,
        private readonly OfficeTaskService $service,
        private readonly TelegramBotClient $bot,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function run(): array
    {
        $st = ['sent' => 0, 'polled' => 0, 'questions' => 0, 'results' => 0, 'delivered' => 0, 'errors' => 0];
        $this->repo->ensureOfficeColumns();

        foreach ($this->repo->dueOutbox(self::BATCH, date('Y-m-d H:i:s', ($this->clock)())) as $row) {
            if ($this->sender->isHalted()) {
                break;
            }
            try {
                if ($this->sender->send($row)) {
                    $st['sent']++;
                }
            } catch (\Throwable $e) {
                $st['errors']++;
                $this->logger->error('aibot.office.send.exception', ['err' => get_class($e)]);
            }
        }
        if ($this->sender->isHalted()) {
            return $st; // учётные данные отвергнуты: опрос бессмыслен
        }

        $since = $this->repo->getState(self::STATE_SINCE);
        $cursor = null;
        $maxTs = null;
        $minFailTs = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            try {
                $r = $this->api->listTasks($since, $cursor, self::PAGE);
            } catch (OfficeTransportException $e) {
                $this->logger->warning('aibot.office.poll.network', ['err' => $e->getMessage()]);
                $st['errors']++;
                return $st;
            }
            if (!$r->ok()) {
                $this->logger->error('aibot.office.poll.http', ['status' => $r->status, 'code' => $r->errorCode()]);
                $st['errors']++;
                return $st;
            }
            foreach ((array) ($r->data['tasks'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $st['polled']++;
                $ts = self::ts($item['updated_at'] ?? null);
                try {
                    $ok = $this->handle($item, $st);
                } catch (\Throwable $e) {
                    $ok = false;
                    $this->logger->error('aibot.office.poll.item_failed', ['task' => $item['task_id'] ?? null, 'err' => get_class($e)]);
                }
                if (!$ok) {
                    $st['errors']++;
                }
                if ($ts === null) {
                    continue;
                }
                if ($ok) {
                    $maxTs = max($maxTs ?? $ts, $ts);
                } else {
                    $minFailTs = min($minFailTs ?? $ts, $ts);
                }
            }
            $cursor = is_string($r->data['next_cursor'] ?? null) && $r->data['next_cursor'] !== '' ? $r->data['next_cursor'] : null;
            if ($cursor === null) {
                break;
            }
        }
        $mark = $minFailTs ?? $maxTs;
        if ($mark !== null) {
            $new = gmdate('Y-m-d\TH:i:s', $mark - 1);
            if ($since === null || $new > $since) { // курсор только вперёд
                $this->repo->setState(self::STATE_SINCE, $new);
            }
        }
        return $st;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,int> $st
     * @return bool false: не всё доставлено, повторить на следующем проходе
     */
    private function handle(array $item, array &$st): bool
    {
        $officeId = (string) ($item['task_id'] ?? '');
        if ($officeId === '') {
            return true;
        }
        $local = $this->repo->findTaskByOfficeId($officeId);
        if ($local === null && preg_match('/^tg-(-?\d+)-(\d+)$/', (string) ($item['client_task_id'] ?? ''), $m) === 1) {
            $local = $this->repo->findTaskByOrigin($m[1], (int) $m[2]);
        }
        if ($local === null) {
            $this->logger->info('aibot.office.poll.unknown_task', ['task' => $officeId]);
            return true;
        }
        $key = (string) $local['task_key'];
        $rev = (int) ($item['revision'] ?? 0);
        $lease = !empty($item['lease_live']);
        $base = ['lease_live' => $lease ? 1 : 0, 'office_revision' => max($rev, (int) ($local['office_revision'] ?? 0))];
        if (($local['office_task_id'] ?? null) !== $officeId) {
            $base['office_task_id'] = $officeId; // ответ на create потерялся: догоняем
        }
        $this->repo->updateTask($key, $base);

        $deliveredRev = (int) ($local['office_delivered_revision'] ?? 0);
        if ($rev <= $deliveredRev) {
            return true;
        }
        $status = (string) ($item['status'] ?? '');
        $ok = true;

        if ($status === 'done') {
            $ok = $this->deliverResult($local, (string) ($item['result'] ?? ''), $st);
        } elseif ($status === 'cancelled') {
            if ((string) $local['status'] !== 'cancelled') {
                $this->repo->updateTask($key, ['status' => 'cancelled']);
                $this->say($local, '🚫 Задача #' . ($local['id'] ?? $key) . ' отменена в офисе');
            }
        } else {
            $questions = array_values(array_filter((array) ($item['questions'] ?? []), 'is_array'));
            usort($questions, static fn($a, $b) => (int) ($a['question_id'] ?? 0) <=> (int) ($b['question_id'] ?? 0));
            foreach ($questions as $q) {
                $qid = (string) (int) ($q['question_id'] ?? 0);
                $text = trim((string) ($q['text'] ?? ''));
                if ($qid === '0' || $text === '') {
                    continue;
                }
                $already = (string) ($this->repo->findTaskByKey($key)['last_question_id'] ?? '') === $qid;
                $id = $this->service->recordQuestion($key, $qid, '❓ ' . self::esc(mb_substr($text, 0, self::RESULT_MAX)));
                if ($id === null) {
                    $ok = false;
                } elseif (!$already) {
                    $st['questions']++;
                }
            }
            if ($ok && $questions === []) {
                $this->repo->updateTask($key, ['status' => $lease ? 'in_progress' : 'waiting_executor']);
            }
        }
        if (!$ok) {
            return false;
        }
        $resp = $this->api->delivered($officeId, $rev);
        if (!$resp->ok()) {
            $this->logger->warning('aibot.office.delivered_failed', ['task' => $officeId, 'status' => $resp->status]);
            return false;
        }
        $this->repo->updateTask($key, ['office_delivered_revision' => max($rev, $deliveredRev)]);
        $st['delivered']++;
        return true;
    }

    /**
     * @param array<string,mixed> $local
     * @param array<string,int> $st
     */
    private function deliverResult(array $local, string $result, array &$st): bool
    {
        $key = (string) $local['task_key'];
        if (($local['result_delivered_at'] ?? null) !== null) {
            return true; // уже отправлен
        }
        $result = trim($result);
        $text = '✅ Задача #' . ($local['id'] ?? $key) . ' выполнена';
        if ($result !== '') {
            $text .= ":\n" . self::esc(mb_substr($result, 0, self::RESULT_MAX))
                . (mb_strlen($result) > self::RESULT_MAX ? "\n…(обрезано)" : '');
        }
        if (!$this->say($local, $text)) {
            return false;
        }
        $this->repo->updateTask($key, ['status' => 'done', 'result_delivered_at' => date('Y-m-d H:i:s', ($this->clock)())]);
        $st['results']++;
        return true;
    }

    /** @param array<string,mixed> $local */
    private function say(array $local, string $text): bool
    {
        $thread = ($local['thread_id'] ?? null) !== null ? (int) $local['thread_id'] : null;
        $id = $this->bot->withChatId((string) $local['chat_id'])
            ->sendMessageWithKeyboard($text, [], $thread, (int) $local['origin_message_id']);
        return (bool) $id;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function ts(mixed $iso): ?int
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso, new \DateTimeZone('UTC')))->getTimestamp(); // Vera: naive UTC
        } catch (\Exception) {
            return null;
        }
    }
}
