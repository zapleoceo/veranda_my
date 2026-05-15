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
                [[['text' => 'Посмотреть меню', 'web_app' => ['url' => 'https://veranda.my/links/menu.php']]],
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
        $returnUrl  = 'https://veranda.my/' . ltrim($sourcePage, '/') . '?tg_state=' . rawurlencode($code);

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

        $this->logger->debug('webhook.callback', ['data' => $data, 'chat' => $chatId, 'user' => $username]);

        if (!preg_match('/^([a-z_]+):(\d+)$/', $data, $m) || !isset(self::ACTION_MAP[$m[1]])) {
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
            $msg = $username !== ''
                ? "Нет доступа для @{$username}. Попросите доступ «✅ Принято (Telegram)»."
                : 'Нет доступа: у вас нет username в Telegram.';
            $this->bot->answerCallbackQuery($callbackId, $msg, true);
            $response->getBody()->write('ok');
            return $response;
        }

        $ctx    = new ActionContext($this->db, $this->bot, $actionId, $chatId, $messageId, $callbackId, $username, $actorName);
        $result = '';
        try {
            /** @var ActionInterface $action */
            $action = new (self::ACTION_MAP[$actionName])();
            $result = $action->handle($ctx);
        } catch (\Throwable $e) {
            $this->logger->error('webhook.action_error', ['action' => $actionName, 'id' => $actionId, 'err' => $e->getMessage()]);
            $result = 'Ошибка: ' . $e->getMessage();
        }

        if ($result !== '') {
            $this->bot->answerCallbackQuery($callbackId, $result);
        }

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
