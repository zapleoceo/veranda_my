<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\TelegramBotClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /aibot_webhook — @Veranda_aibot.
 *
 * Сценарий: разрешённый пользователь в разрешённом чате отвечает на чьё-то
 * сообщение «внеси, категория инвесторы, …» → бот разбирает ИСХОДНОЕ
 * сообщение (это данные, не инструкции) и показывает карточку-черновик.
 * В Poster ничего не пишется до нажатия «Внести».
 *
 * Отказ всегда «закрытый»: неизвестный чат/пользователь — молча игнор,
 * старое (>maxUpdateAgeSec) обновление — игнор (Telegram досылает
 * накопившиеся апдейты после простоя, задним числом не исполняем).
 */
final class AiBotWebhookController
{
    private const TRIGGER_RE = '/^\s*(?:@\w+[\s,:]*)?(?:внеси|занеси|запиши|проведи)(?![\p{L}])/iu';
    private const INTENT_RE = '/инвестор|дивиденд/iu';
    private const CALLBACK_RE = '/^fd_(acc|date|split|go|cancel|rep|skip):(\d+)(?::(\d+))?$/';

    /** @var \Closure(): int */
    private \Closure $clock;
    /** @var \Closure(\Closure): void */
    private \Closure $defer;

    public function __construct(
        private readonly AiBotConfig $config,
        private readonly FinanceDraftService $drafts,
        private readonly TelegramBotClient $bot,
        private readonly PayoutParser $parser,
        private readonly ?PayoutExtractorInterface $extractor,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
        ?\Closure $defer = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
        // ИИ-фолбэк медленный — выполняем после ответа Telegram (как афиша),
        // иначе Telegram сочтёт вебхук зависшим и начнёт повторы.
        $this->defer = $defer ?? static function (\Closure $job): void {
            register_shutdown_function(static function () use ($job): void {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                ignore_user_abort(true);
                set_time_limit(120);
                $job();
            });
        };
    }

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $update = json_decode((string) $request->getBody(), true);
        $update = is_array($update) ? $update : [];
        try {
            if (is_array($update['message'] ?? null)) {
                $this->onMessage($update['message']);
            } elseif (is_array($update['callback_query'] ?? null)) {
                $this->onCallback($update['callback_query']);
            }
        } catch (\Throwable $e) {
            // 200 всё равно: иначе Telegram будет бесконечно повторять апдейт.
            $this->logger->error('aibot.error', ['err' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
        }
        $response->getBody()->write('ok');
        return $response;
    }

    private function onMessage(array $msg): void
    {
        $chatId = (string) ($msg['chat']['id'] ?? '');
        $fromId = (int) ($msg['from']['id'] ?? 0);
        $text = trim((string) ($msg['text'] ?? $msg['caption'] ?? ''));
        $reply = is_array($msg['reply_to_message'] ?? null) ? $msg['reply_to_message'] : null;

        if (!$this->config->chatAllowed($chatId)) {
            $this->logger->info('aibot.skip.chat', ['chat' => $chatId]);
            return;
        }
        if ($reply === null || !$this->isTrigger($text)) {
            return; // обычная переписка — не наше дело
        }
        // Пересланное / цитата / от имени чата — не команда владельца, даже если текст его.
        if (self::isRelayed($msg)) {
            $this->logger->warning('aibot.skip.relayed', ['chat' => $chatId, 'from' => $fromId]);
            return;
        }
        // Только числовой from.id владельца; username не учитывается вовсе.
        if (!$this->config->canWrite($fromId)) {
            $this->logger->warning('aibot.skip.user', ['chat' => $chatId, 'from' => $fromId, 'perm' => AiBotConfig::PERMISSION_KEY]);
            return;
        }
        $age = ($this->clock)() - (int) ($msg['date'] ?? 0);
        if ($age > $this->config->maxUpdateAgeSec) {
            $this->logger->info('aibot.skip.stale', ['chat' => $chatId, 'msg' => $msg['message_id'] ?? null, 'age' => $age]);
            return;
        }

        $triggerId = (int) ($msg['message_id'] ?? 0);
        $threadId = !empty($msg['is_topic_message']) ? (int) ($msg['message_thread_id'] ?? 0) : null;
        $bot = $this->bot->withChatId($chatId);

        if (!preg_match(self::INTENT_RE, $text)) {
            $bot->sendMessageWithKeyboard('Пока умею вносить только расходы категории «Инвесторы» (дивиденды).', [], $threadId, $triggerId);
            return;
        }

        $sourceId = (int) ($reply['message_id'] ?? 0);
        $sourceText = (string) ($reply['text'] ?? $reply['caption'] ?? '');
        $sourceDate = (int) ($reply['date'] ?? 0);

        // Ответ должен быть на обычное сообщение человека с текстом.
        $hint = match (true) {
            !empty($reply['forum_topic_created']) => 'Ответьте на само сообщение с выплатами, а не на заголовок темы.',
            !empty($reply['from']['is_bot']) => 'Ответьте на сообщение человека с выплатами, а не бота.',
            trim($sourceText) === '' => 'В сообщении, на которое вы ответили, нет текста с выплатами.',
            default => null,
        };
        if ($hint !== null) {
            $this->logger->info('aibot.skip.reply_target', ['chat' => $chatId, 'source' => $sourceId]);
            $bot->sendMessageWithKeyboard($hint, [], $threadId, $triggerId);
            return;
        }

        $parsed = $this->parser->parse($sourceText);
        $work = function () use ($chatId, $sourceId, $triggerId, $fromId, $sourceDate, $parsed, $sourceText, $bot, $threadId): void {
            if ($parsed['people'] === [] && $this->extractor !== null && $this->extractor->isAvailable()) {
                try {
                    $people = $this->extractor->extract($sourceText);
                    if ($people !== []) {
                        $parsed = PayoutParser::cap($people, PayoutParser::period($sourceText));
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('aibot.llm_failed', ['err' => $e->getMessage()]);
                }
            }
            $res = $this->drafts->createOrGet($chatId, $sourceId, $triggerId, $fromId, $sourceDate, $parsed,
                function (array $draft) use ($bot, $threadId, $triggerId): ?int {
                    $card = $this->drafts->render($draft);
                    return $bot->sendMessageWithKeyboard($card['text'], $card['keyboard'], $threadId, $triggerId);
                });
            $draft = $res['draft'];
            $this->logger->info('aibot.draft', ['id' => $draft['id'] ?? null, 'created' => $res['created'], 'rows' => count($parsed['people'])]);
            if ($res['created']) {
                return;
            }
            // Повторный триггер на тот же источник — показываем существующий результат.
            $status = (string) ($draft['status'] ?? '');
            if (in_array($status, ['done', 'partial'], true)) {
                $bot->sendMessageWithKeyboard($this->drafts->journal($draft), [], $threadId, $triggerId);
            } elseif (empty($draft['card_msg_id'])) {
                $card = $this->drafts->render($draft);
                $id = $bot->sendMessageWithKeyboard($card['text'], $card['keyboard'], $threadId, $triggerId);
                if ($id) {
                    $this->drafts->attachCard((int) $draft['id'], $id);
                }
            } else {
                $bot->sendMessageWithKeyboard('Черновик #' . (int) $draft['id'] . ' по этому сообщению уже есть — карточка выше.',
                    [], $threadId, (int) $draft['card_msg_id']);
            }
        };
        // Отложенная работа идёт уже после ответа Telegram — исключение здесь
        // никто не увидит, поэтому ловим, пишем в лог и говорим в чат.
        $job = function () use ($work, $bot, $threadId, $triggerId, $chatId, $sourceId): void {
            try {
                $work();
            } catch (\Throwable $e) {
                $this->logger->error('aibot.error', ['chat' => $chatId, 'source' => $sourceId, 'err' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
                try {
                    $bot->sendMessageWithKeyboard('⚠️ Не удалось подготовить черновик — ничего не внесено. Попробуйте ещё раз.', [], $threadId, $triggerId);
                } catch (\Throwable) {
                }
            }
        };

        if ($parsed['people'] === [] && $this->extractor !== null && $this->extractor->isAvailable()) {
            ($this->defer)($job);
        } else {
            $job();
        }
    }

    private function onCallback(array $cb): void
    {
        $cbId = (string) ($cb['id'] ?? '');
        $data = (string) ($cb['data'] ?? '');
        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        $msgId = (int) ($cb['message']['message_id'] ?? 0);
        $fromId = (int) ($cb['from']['id'] ?? 0);

        if (!preg_match(self::CALLBACK_RE, $data, $m)) {
            $this->bot->answerCallbackQuery($cbId, 'Неизвестная кнопка', true);
            return;
        }
        if (!$this->config->chatAllowed($chatId) || !$this->config->canWrite($fromId)) {
            $this->logger->warning('aibot.callback.denied', ['chat' => $chatId, 'from' => $fromId, 'data' => $data]);
            $this->bot->answerCallbackQuery($cbId, 'Нет доступа', true);
            return;
        }
        [$action, $draftId, $arg] = [$m[1], (int) $m[2], $m[3] ?? ''];
        $draft = $this->drafts->get($draftId);
        // Кнопка должна принадлежать именно этой карточке в этом чате.
        if ($draft === null || (string) $draft['chat_id'] !== $chatId
            || (!empty($draft['card_msg_id']) && (int) $draft['card_msg_id'] !== $msgId)) {
            $this->bot->answerCallbackQuery($cbId, 'Черновик не найден', true);
            return;
        }
        // Подтверждает только тот же владелец, что дал команду.
        if ((int) $draft['initiator_tg_id'] !== $fromId) {
            $this->logger->warning('aibot.callback.not_initiator', ['draft' => $draftId, 'from' => $fromId]);
            $this->bot->answerCallbackQuery($cbId, 'Подтвердить может только автор команды', true);
            return;
        }

        $bot = $this->bot->withChatId($chatId);
        if ($action === 'rep' || $action === 'skip') {
            // Решение по «возможному дублю» — только по явной кнопке владельца;
            // повторный callback по уже решённой записи ничего не меняет.
            $toast = $this->drafts->decideDuplicate($draftId, (int) $arg, $action, $fromId);
            $draft = (array) $this->drafts->get($draftId);
            if ($action === 'skip' || (string) ($draft['status'] ?? '') !== 'partial') {
                $card = $this->drafts->render($draft);
                $bot->editMessageText($msgId, $card['text'], $card['keyboard']);
                $this->bot->answerCallbackQuery($cbId, $toast);
                return;
            }
            // Повтор разрешён — вносим только эту запись (остальные уже решены).
        }
        if ($action === 'go' || $action === 'rep') {
            $res = $this->drafts->execute($draftId, $fromId);
            $after = $res['draft'] !== [] ? $res['draft'] : $draft;
            $card = $this->drafts->render($after);
            $bot->editMessageText($msgId, $card['text'], $card['keyboard']);
            $this->bot->answerCallbackQuery($cbId, $res['message'], !in_array((string) ($after['status'] ?? ''), ['done', 'partial', 'review'], true));
            if ((string) ($draft['status'] ?? '') !== (string) ($after['status'] ?? '') || (string) ($draft['poster_tx_ids_json'] ?? '') !== (string) ($after['poster_tx_ids_json'] ?? '')) {
                if (in_array((string) ($after['status'] ?? ''), ['done', 'partial', 'review'], true)) {
                    $bot->sendMessageWithKeyboard($this->drafts->journal($after), [], null, $msgId);
                }
            }
            return;
        }

        $toast = $this->drafts->applyChoice($draftId, $action, $arg);
        $card = $this->drafts->render((array) $this->drafts->get($draftId));
        $bot->editMessageText($msgId, $card['text'], $card['keyboard']);
        $this->bot->answerCallbackQuery($cbId, $toast);
    }

    private static function isRelayed(array $msg): bool
    {
        foreach (['forward_origin', 'forward_from', 'forward_from_chat', 'forward_date', 'forward_sender_name', 'quote', 'sender_chat', 'via_bot'] as $k) {
            if (!empty($msg[$k])) {
                return true;
            }
        }
        return !empty($msg['is_automatic_forward']);
    }

    private function isTrigger(string $text): bool
    {
        if (preg_match(self::TRIGGER_RE, $text)) {
            return true;
        }
        return $this->config->botUsername !== '' && stripos($text, '@' . $this->config->botUsername) !== false;
    }
}
