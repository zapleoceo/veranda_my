<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Actions\ActionContext;
use App\Actions\ActionInterface;
use App\Actions\IgnoreItemAction;
use App\Actions\IgnoreTxAction;
use App\Actions\VdeclineAction;
use App\Actions\VposterAction;
use App\Actions\VposterCancelAction;
use App\Actions\VposterFixAction;
use App\Actions\VrestoreAction;
use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\TelegramBotClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class WebhookController
{
    private const ACTION_MAP = [
        'ignore_item'    => IgnoreItemAction::class,
        'ignore_tx'      => IgnoreTxAction::class,
        'vposter'        => VposterAction::class,
        'vdecline'       => VdeclineAction::class,
        'vrestore'       => VrestoreAction::class,
        'vposter_fix'    => VposterFixAction::class,
        'vposter_cancel' => VposterCancelAction::class,
    ];

    private const POSTER_ACTIONS = ['vposter', 'vdecline', 'vrestore', 'vposter_fix', 'vposter_cancel'];
    private const IGNORE_ACTIONS = ['ignore_item', 'ignore_tx'];

    // Friendly Russian label per action group — shown in denial popups so the
    // user understands which checkbox in /admin/access needs to be ticked.
    // Mirrors src/Controllers/Admin/AccessController::PERMISSION_KEYS.
    private const ACTION_PERMISSION_LABEL = [
        'ignore_item'    => 'Игнор + ✅ Принято',
        'ignore_tx'      => 'Игнор + ✅ Принято',
        'vposter'        => 'Кнопка «Бронь в Постере»',
        'vdecline'       => 'Кнопка «Бронь в Постере»',
        'vrestore'       => 'Кнопка «Бронь в Постере»',
        'vposter_fix'    => 'Кнопка «Бронь в Постере»',
        'vposter_cancel' => 'Кнопка «Бронь в Постере»',
    ];

    public function __construct(
        private Database $db,
        private TelegramBotClient $bot,
        private LoggerInterface $logger,
    ) {}

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $waEvent = strtolower(trim((string) ($request->getQueryParams()['wa_event'] ?? '')));
        if ($waEvent !== '') {
            return (new WaEventHandler($this->db, $this->bot))->handle($request, $response, $waEvent);
        }

        $update = json_decode((string) $request->getBody(), true) ?? [];

        if (!empty($update['message'])) {
            return $this->_handleMessage($response, (array) $update['message']);
        }

        if (!empty($update['callback_query'])) {
            return $this->_handleCallbackQuery($response, (array) $update['callback_query']);
        }

        $response->getBody()->write('ok');
        return $response;
    }

    private function _handleMessage(ResponseInterface $response, array $msg): ResponseInterface
    {
        $chat   = is_array($msg['chat'] ?? null) ? $msg['chat'] : [];
        $chatId = (string) ($chat['id'] ?? '');
        $text   = trim((string) ($msg['text'] ?? ''));
        $cmd    = strtolower((string) preg_replace('/\s+.*/', '', $text));

        if ($cmd === '/start' && $chatId !== '' && ($chat['type'] ?? '') === 'private') {
            if (preg_match('/^\/start(?:@\w+)?\s+([a-f0-9]{8,40})$/i', $text, $m)) {
                $this->_handleReservationStart($chatId, strtolower($m[1]), $msg['from'] ?? []);
                $response->getBody()->write('ok');
                return $response;
            }
            $this->bot->withChatId($chatId)->sendMessageWithKeyboard(
                'Выбери действие:',
                [[['text' => 'Посмотреть меню', 'web_app' => ['url' => Config::baseUrl() . '/links/menu.php']]],
                 [['text' => 'Как добраться', 'url' => 'https://maps.app.goo.gl/wM9MMAGJjxUppDgR9']]]
            );
        } elseif (in_array($cmd, ['/start', '/menu'], true) && $chatId !== '' && ($chat['type'] ?? '') !== 'private') {
            $this->bot->withChatId($chatId)->sendMessage('Напиши мне в личку: @VerandamyBot');
        }

        $response->getBody()->write('ok');
        return $response;
    }

    private function _handleReservationStart(string $chatId, string $code, array $from): void
    {
        $t   = $this->db->t('table_reservation_tg_states');
        $row = $this->db->query(
            "SELECT code, payload_json FROM {$t} WHERE code = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1",
            [$code]
        )->fetch();

        if (!is_array($row) || empty($row['code'])) {
            return;
        }

        $tgUserId  = (int) ($from['id'] ?? 0);
        $tgUsername = ltrim(strtolower(trim((string) ($from['username'] ?? ''))), '@');
        $tgName    = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));

        if ($tgUserId > 0 || $tgUsername !== '' || $tgName !== '') {
            $this->db->query(
                "UPDATE {$t} SET tg_user_id = NULLIF(?,0), tg_username = NULLIF(?,''), tg_name = NULLIF(?,'') WHERE code = ?",
                [$tgUserId, $tgUsername, $tgName, $code]
            );
        }

        $payload    = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        $sourcePage = is_array($payload) && !empty($payload['source_page']) ? $payload['source_page'] : 'Tr2.php';
        $returnUrl  = Config::baseUrl() . '/' . ltrim($sourcePage, '/') . '?tg_state=' . rawurlencode($code);

        $msgId = $this->bot->withChatId($chatId)->sendMessageWithKeyboard(
            "Аккаунт подтвержден.\nНажми кнопку ниже, чтобы завершить бронирование:",
            [[['text' => 'Завершить бронирование', 'url' => $returnUrl]]]
        );

        if ($msgId > 0) {
            $this->db->query(
                "UPDATE {$t} SET return_sent_at = ?, return_msg_id = NULLIF(?,0) WHERE code = ?",
                [date('Y-m-d H:i:s'), $msgId, $code]
            );
        }
    }

    private function _handleCallbackQuery(ResponseInterface $response, array $callback): ResponseInterface
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data       = (string) ($callback['data'] ?? '');
        $message    = is_array($callback['message'] ?? null) ? $callback['message'] : [];
        $messageId  = (int) ($message['message_id'] ?? 0);
        $chatId     = (string) ($message['chat']['id'] ?? '');
        $from       = is_array($callback['from'] ?? null) ? $callback['from'] : [];
        $username   = ltrim(strtolower(trim((string) ($from['username'] ?? ''))), '@');
        $actorName  = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: ($username ?: 'unknown');

        // Log every callback at INFO so the operator can trace exactly what
        // arrived (who clicked, what data, on which message) without having
        // to enable debug logging.
        $this->logger->info('webhook.callback', [
            'data'    => $data,
            'chat'    => $chatId,
            'msg_id'  => $messageId,
            'user'    => $username,
            'name'    => $actorName,
        ]);

        if (!preg_match('/^([a-z_]+):(\d+)$/', $data, $m) || !isset(self::ACTION_MAP[$m[1]])) {
            // Telegram leaves the button in a "loading" spinner forever if we
            // don't answer the callback. Always close the loop with a visible
            // message, even for unknown actions.
            $this->logger->warning('webhook.callback.unknown_data', ['data' => $data, 'user' => $username]);
            $this->bot->answerCallbackQuery(
                $callbackId,
                'Неизвестная кнопка: ' . ($data !== '' ? $data : '(пустые данные)'),
                true
            );
            $response->getBody()->write('ok');
            return $response;
        }

        $actionName = $m[1];
        $actionId   = (int) $m[2];
        $perms      = $this->_permissions($username);

        $allowed = match (true) {
            in_array($actionName, self::POSTER_ACTIONS, true) => $perms['canPoster'],
            in_array($actionName, self::IGNORE_ACTIONS, true) => $perms['canIgnore'],
            default                                            => false,
        };

        if (!$allowed) {
            $permLabel = self::ACTION_PERMISSION_LABEL[$actionName] ?? $actionName;
            $msg = $username !== ''
                ? "Нет доступа для @{$username}.\nНужно право «{$permLabel}» — попросите админа поставить галочку на /admin/access."
                : 'Нет доступа: у вашего Telegram нет публичного @username — добавьте его в настройках профиля Telegram, затем попросите админа выдать права на /admin/access.';
            $this->logger->warning('webhook.callback.denied', [
                'action'   => $actionName,
                'user'     => $username ?: '(no username)',
                'has_user' => $username !== '',
                'perms'    => $perms,
            ]);
            $this->bot->answerCallbackQuery($callbackId, $msg, true);
            $response->getBody()->write('ok');
            return $response;
        }

        // Bind the bot to the chat where the callback came from. Without
        // this, actions calling $ctx->bot->deleteMessage()/editMessageText()
        // hit Telegram with chat_id="" because the DI-built bot has no
        // default chat (TELEGRAM_CHAT_ID is only set in TelegramAlertService).
        // Symptom in logs: telegram.api_error 'Bad Request: chat identifier
        // is not specified'.
        $boundBot = $this->bot->withChatId($chatId);
        $ctx      = new ActionContext($this->db, $boundBot, $actionId, $chatId, $messageId, $callbackId, $username, $actorName);
        $result   = '';
        $errored  = false;
        try {
            /** @var ActionInterface $action */
            $action = new (self::ACTION_MAP[$actionName])();
            $result = $action->handle($ctx);
        } catch (\Throwable $e) {
            $errored = true;
            $this->logger->error('webhook.action_error', [
                'action' => $actionName,
                'id'     => $actionId,
                'err'    => $e->getMessage(),
                'file'   => $e->getFile(),
                'line'   => $e->getLine(),
            ]);
            // Show the actual exception text in the popup (truncated to
            // Telegram's 200-char limit) so the operator can copy it back.
            $result = 'Ошибка: ' . substr($e->getMessage(), 0, 180);
        }

        if ($result !== '') {
            // Errors: show as alert (modal popup that requires dismiss).
            // Successes: short toast that auto-dismisses.
            $this->bot->answerCallbackQuery($callbackId, $result, $errored);
        }
        // If the action returned '' it already called answerCallbackQuery
        // itself — we don't double-answer (Telegram returns 400 otherwise).

        $response->getBody()->write('ok');
        return $response;
    }

    private function _permissions(string $username): array
    {
        $perms = ['canIgnore' => false, 'canPoster' => false];
        if ($username === '') {
            return $perms;
        }

        $t   = $this->db->t('users');
        $row = $this->db->query("SELECT permissions_json FROM {$t} WHERE telegram_username = ? LIMIT 1", [$username])->fetch();
        $p   = is_array($row) ? (json_decode((string) ($row['permissions_json'] ?? '{}'), true) ?? []) : [];

        $isAdmin        = !empty($p['admin']);
        $perms['canIgnore'] = $isAdmin || !empty($p['telegram_ack']) || !empty($p['exclude_toggle']);
        $perms['canPoster'] = $isAdmin || !empty($p['vposter_button']);

        return $perms;
    }
}
