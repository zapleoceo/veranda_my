<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\TelegramBotClient;
use App\Payday3\Contracts\NamedLockInterface;
use Psr\Log\LoggerInterface;

/**
 * Приём задач в офис через @Veranda_aibot (локальная часть, до границы outbox).
 *
 *  - Задача: сообщение, НАЧИНАЮЩЕЕСЯ с @<botUsername> + текст, от автора с правом
 *    `aibot_office` (числовой from.id, проверка свежая, без кэша).
 *  - Ответ: reply автора с правом на вопрос бота (last_question_msg_id задачи в
 *    статусе waiting_owner) -> строка outbox 'answer', revision+1.
 *  - Текст, цитируемое сообщение и прочее из Telegram — ДАННЫЕ, не инструкции.
 *  - В сеть к офису не ходит: строки лежат в tg_office_outbox (см. OfficeOutboxSenderInterface).
 *
 * Отказ всегда закрытый и тихий (только лог): чужие, боты, пересланное, цитаты,
 * sender_chat, via_bot, устаревшие апдейты.
 */
final class OfficeTaskService
{
    public const STATUS_QUEUED = 'queued_local';
    public const STATUS_WAITING_OWNER = 'waiting_owner';

    private const CONTEXT_TEXT_MAX = 4000;
    private const LOCK_TIMEOUT = 5;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly AiBotConfig $config,
        private readonly OfficeTaskRepositoryInterface $repo,
        private readonly TelegramBotClient $bot,
        private readonly PermissionAuthorizerInterface $authorizer,
        private readonly NamedLockInterface $lock,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Текст задачи, если сообщение начинается с @<botUsername>; иначе null. */
    public function extractTaskText(string $text): ?string
    {
        $name = $this->config->botUsername;
        if ($name === '') {
            return null;
        }
        if (!preg_match('/^\s*@' . preg_quote($name, '/') . '(?![\w])[\s,:;\-]*(.*)$/isu', $text, $m)) {
            return null;
        }
        $task = trim($m[1]);
        return $task === '' ? null : $task;
    }

    /**
     * @param array<string,mixed> $msg сообщение Telegram
     * @return bool true — сообщение относится к office-сценарию (принято или осознанно отклонено),
     *              остальным обработчикам дальше делать нечего
     */
    public function handleMessage(array $msg, int $updateId): bool
    {
        $text = trim((string) ($msg['text'] ?? $msg['caption'] ?? ''));
        $reply = is_array($msg['reply_to_message'] ?? null) ? $msg['reply_to_message'] : null;
        $chatId = (string) ($msg['chat']['id'] ?? '');
        $taskText = $this->extractTaskText($text);

        $question = null;
        if ($taskText === null && $reply !== null && $text !== '') {
            $question = $this->repo->findWaitingByQuestionMsg($chatId, (int) ($reply['message_id'] ?? 0));
            if ($question === null) {
                return false; // обычная переписка
            }
        } elseif ($taskText === null) {
            if ($text === '' && self::extractMedia($msg) !== []) {
                // Голос/фото без подписи нельзя адресовать @упоминанием.
                $this->logger->info('aibot.office.skip.media_no_caption', ['chat' => $chatId, 'msg' => $msg['message_id'] ?? null]);
            }
            return false;
        }

        if (!$this->admissible($msg, $chatId)) {
            return true;
        }
        if ($question !== null) {
            $this->recordAnswer($question, $msg, $text, $updateId);
        } else {
            $this->intake($msg, (string) $taskText, $updateId);
        }
        return true;
    }

    /**
     * Бот задаёт вопрос по задаче: сообщение уходит ТОЛЬКО в чат/тему задачи,
     * ответом на исходное сообщение. Идемпотентно по $questionId.
     *
     * @return int|null message_id вопроса (в т.ч. прежний при повторе); null — не отправлено
     */
    public function recordQuestion(string $taskKey, string $questionId, string $text): ?int
    {
        return $this->lock->synchronized('aibot_office_task_' . $taskKey, self::LOCK_TIMEOUT, function () use ($taskKey, $questionId, $text): ?int {
            $task = $this->repo->findTaskByKey($taskKey);
            if ($task === null || in_array((string) $task['status'], ['done', 'failed', 'cancelled'], true)) {
                return null;
            }
            if ((string) ($task['last_question_id'] ?? '') === $questionId) {
                return $task['last_question_msg_id'] !== null ? (int) $task['last_question_msg_id'] : null;
            }
            $thread = $task['thread_id'] !== null ? (int) $task['thread_id'] : null;
            $id = $this->bot->withChatId((string) $task['chat_id'])
                ->sendMessageWithKeyboard($text, [], $thread, (int) $task['origin_message_id']);
            if (!$id) {
                $this->logger->warning('aibot.office.question_not_sent', ['task' => $taskKey]);
                return null;
            }
            $this->repo->updateTask($taskKey, [
                'last_question_id' => $questionId,
                'last_question_msg_id' => $id,
                'status' => self::STATUS_WAITING_OWNER,
            ]);
            return $id;
        });
    }

    /** @param array<string,mixed> $msg */
    private function admissible(array $msg, string $chatId): bool
    {
        $fromId = (int) ($msg['from']['id'] ?? 0);
        if (!$this->config->chatAllowed($chatId)) {
            $this->logger->info('aibot.office.skip.chat', ['chat' => $chatId]);
            return false;
        }
        if (!empty($msg['from']['is_bot']) || $fromId <= 0) {
            $this->logger->info('aibot.office.skip.bot', ['chat' => $chatId]);
            return false;
        }
        if (AiBotWebhookController::isRelayed($msg)) {
            $this->logger->warning('aibot.office.skip.relayed', ['chat' => $chatId, 'from' => $fromId]);
            return false;
        }
        if (!$this->authorizer->allows($fromId)) {
            $this->logger->warning('aibot.office.skip.user', ['chat' => $chatId, 'from' => $fromId]);
            return false;
        }
        $age = ($this->clock)() - (int) ($msg['date'] ?? 0);
        if ($age > $this->config->maxUpdateAgeSec) {
            $this->logger->info('aibot.office.skip.stale', ['chat' => $chatId, 'msg' => $msg['message_id'] ?? null, 'age' => $age]);
            return false;
        }
        return true;
    }

    /** @param array<string,mixed> $msg */
    private function intake(array $msg, string $taskText, int $updateId): void
    {
        $chatId = (string) $msg['chat']['id'];
        $messageId = (int) ($msg['message_id'] ?? 0);
        $fromId = (int) $msg['from']['id'];
        $threadId = !empty($msg['is_topic_message']) ? (int) ($msg['message_thread_id'] ?? 0) : null;
        $taskKey = 'vb-' . $chatId . '-' . $messageId;
        $context = $this->replyContext($msg);
        $media = self::extractMedia($msg);

        $this->lock->synchronized('aibot_office_msg_' . $chatId . '_' . $messageId, self::LOCK_TIMEOUT,
            function () use ($chatId, $messageId, $fromId, $threadId, $taskKey, $taskText, $updateId, $context, $media): void {
                $existing = $this->repo->findTaskByOrigin($chatId, $messageId);
                $created = $existing === null;
                if ($created) {
                    $this->repo->insertTask([
                        'task_key' => $taskKey,
                        'chat_id' => $chatId,
                        'thread_id' => $threadId,
                        'origin_message_id' => $messageId,
                        'origin_user_id' => $fromId,
                        'update_id' => $updateId,
                        'text' => $taskText,
                        'context_json' => $context !== null ? self::enc($context) : null,
                        'media_json' => $media !== [] ? self::enc($media) : null,
                        'media_status' => $media !== [] ? 'pending' : 'none',
                        'status' => self::STATUS_QUEUED,
                        'revision' => 1,
                    ]);
                    $existing = $this->repo->findTaskByKey($taskKey);
                }
                // Повторно безопасно: если прошлый запрос упал между задачей и outbox — догоняем.
                if ($this->repo->findOutboxByKey($taskKey) === null) {
                    $this->repo->insertOutbox([
                        'idempotency_key' => $taskKey,
                        'task_key' => $taskKey,
                        'kind' => 'create',
                        'payload_json' => self::enc([
                            'task_key' => $taskKey,
                            'chat_id' => $chatId,
                            'thread_id' => $threadId,
                            'origin_message_id' => $messageId,
                            'origin_user_id' => $fromId,
                            'update_id' => $updateId,
                            'text' => $taskText,
                            'context' => $context,
                            'media' => $media,
                            'revision' => 1,
                        ]),
                        'status' => 'pending',
                        'attempts' => 0,
                    ]);
                }
                if (!$created) {
                    $this->logger->info('aibot.office.duplicate', ['task' => $taskKey, 'update' => $updateId]);
                    return;
                }
                $this->bot->withChatId($chatId)->sendMessageWithKeyboard(
                    '🗂 Задача #' . $this->shortId($existing, $taskKey) . ' принята'
                    . ($media !== [] ? ' (вложений: ' . count($media) . ')' : '') . ', ждёт отправки в офис',
                    [], $threadId, $messageId
                );
            });
    }

    /**
     * @param array<string,mixed> $question задача, найденная вне лока
     * @param array<string,mixed> $msg
     */
    private function recordAnswer(array $question, array $msg, string $text, int $updateId): void
    {
        $chatId = (string) $msg['chat']['id'];
        $messageId = (int) ($msg['message_id'] ?? 0);
        $fromId = (int) $msg['from']['id'];
        $taskKey = (string) $question['task_key'];
        $questionMsgId = (int) $question['last_question_msg_id'];
        $key = 'vb-ans-' . $chatId . '-' . $messageId;

        $this->lock->synchronized('aibot_office_task_' . $taskKey, self::LOCK_TIMEOUT,
            function () use ($taskKey, $questionMsgId, $key, $chatId, $messageId, $fromId, $text, $updateId): void {
                // Перечитываем под локом: статус/вопрос могли измениться.
                $task = $this->repo->findTaskByKey($taskKey);
                if ($task === null || (string) $task['status'] !== self::STATUS_WAITING_OWNER
                    || (int) ($task['last_question_msg_id'] ?? 0) !== $questionMsgId) {
                    $this->logger->info('aibot.office.answer.skip', ['task' => $taskKey]);
                    return;
                }
                if ($this->repo->findOutboxByKey($key) !== null) {
                    $this->logger->info('aibot.office.answer.duplicate', ['key' => $key]);
                    return;
                }
                $revision = (int) $task['revision'] + 1;
                $this->repo->insertOutbox([
                    'idempotency_key' => $key,
                    'task_key' => $taskKey,
                    'kind' => 'answer',
                    'payload_json' => self::enc([
                        'task_key' => $taskKey,
                        'question_id' => (string) ($task['last_question_id'] ?? ''),
                        'question_message_id' => $questionMsgId,
                        'chat_id' => $chatId,
                        'answer_message_id' => $messageId,
                        'answer_user_id' => $fromId,
                        'text' => $text,
                        'causation_update_id' => $updateId,
                        'revision' => $revision,
                    ]),
                    'status' => 'pending',
                    'attempts' => 0,
                ]);
                $this->repo->updateTask($taskKey, ['revision' => $revision]);
            });
    }

    /**
     * Цитируемое сообщение — только данные для офиса.
     *
     * @param array<string,mixed> $msg
     * @return array<string,mixed>|null
     */
    private function replyContext(array $msg): ?array
    {
        $r = $msg['reply_to_message'] ?? null;
        if (!is_array($r) || !empty($r['forum_topic_created'])) {
            return null;
        }
        return [
            'reply_to' => [
                'message_id' => (int) ($r['message_id'] ?? 0),
                'user_id' => isset($r['from']['id']) ? (int) $r['from']['id'] : null,
                'is_bot' => !empty($r['from']['is_bot']),
                'date' => (int) ($r['date'] ?? 0),
                'text' => mb_substr((string) ($r['text'] ?? $r['caption'] ?? ''), 0, self::CONTEXT_TEXT_MAX),
                'media' => self::extractMedia($r),
            ],
        ];
    }

    /** @param array<string,mixed>|null $task */
    private function shortId(?array $task, string $fallback): string
    {
        return isset($task['id']) ? (string) $task['id'] : $fallback;
    }

    /**
     * Идентификаторы вложений (только данные Telegram, файлы не скачиваются).
     *
     * @param array<string,mixed> $msg
     * @return list<array<string,mixed>>
     */
    public static function extractMedia(array $msg): array
    {
        $out = [];
        $add = static function (string $type, mixed $f) use (&$out): void {
            if (!is_array($f) || ($f['file_id'] ?? '') === '') {
                return;
            }
            $item = ['type' => $type, 'file_id' => (string) $f['file_id'], 'file_unique_id' => (string) ($f['file_unique_id'] ?? '')];
            foreach (['mime_type' => 's', 'file_size' => 'i', 'duration' => 'i', 'width' => 'i', 'height' => 'i'] as $k => $t) {
                if (isset($f[$k])) {
                    $item[$k] = $t === 'i' ? (int) $f[$k] : (string) $f[$k];
                }
            }
            $out[] = $item;
        };
        $add('voice', $msg['voice'] ?? null);
        $add('audio', $msg['audio'] ?? null);
        $add('video_note', $msg['video_note'] ?? null);
        $add('document', $msg['document'] ?? null);
        if (is_array($msg['photo'] ?? null) && $msg['photo'] !== []) {
            $add('photo', end($msg['photo'])); // самый крупный размер
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function enc(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
