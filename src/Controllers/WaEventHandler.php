<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\TelegramBotClient;
use App\Repositories\MetaRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class WaEventHandler
{
    public function __construct(
        private Database $db,
        private TelegramBotClient $bot,
    ) {}

    public function handle(ServerRequestInterface $request, ResponseInterface $response, string $event): ResponseInterface
    {
        if (!$this->_isAuthorized($request)) {
            return $this->_json($response, ['ok' => false, 'error' => 'Forbidden'], 403);
        }

        $meta = new MetaRepository($this->db);

        return match ($event) {
            'qr'     => $this->_handleQr($request, $response, $meta),
            'active' => $this->_handleActive($response, $meta),
            default  => $this->_json($response, ['ok' => false, 'error' => 'Unknown wa_event'], 400),
        };
    }

    private function _isAuthorized(ServerRequestInterface $request): bool
    {
        $secret = Config::get('WA_NODE_SECRET') ?: Config::get('WA_BRIDGE_SECRET');
        if ($secret === '') {
            return false;
        }
        $provided = $request->getHeaderLine('X-WA-BRIDGE');
        if ($provided === '') {
            $params   = array_merge($request->getQueryParams(), (array) ($request->getParsedBody() ?? []));
            $provided = (string) ($params['secret'] ?? '');
        }
        return $provided !== '' && hash_equals($secret, $provided);
    }

    private function _handleQr(ServerRequestInterface $request, ResponseInterface $response, MetaRepository $meta): ResponseInterface
    {
        $params    = array_merge($request->getQueryParams(), (array) ($request->getParsedBody() ?? []));
        $defaultChatId  = Config::get('TELEGRAM_CHAT_ID') ?: Config::get('TG_CHAT_ID');
        $defaultThreadId = Config::int('TELEGRAM_THREAD_ID') ?: Config::int('TG_THREAD_ID');

        $chatId   = (string) ($params['chat_id'] ?? $defaultChatId);
        $threadId = (int) ($params['thread_id'] ?? $defaultThreadId);
        $incomingMsgId = (int) ($params['message_id'] ?? 0);
        $text      = trim((string) ($params['text'] ?? ''));
        $photoUrl  = trim((string) ($params['photo_url'] ?? ''));
        $caption   = trim((string) ($params['caption'] ?? ''));

        $sentMsgId  = $incomingMsgId;
        $sentChatId = $chatId;

        if ($sentMsgId <= 0 && $chatId !== '') {
            $chat = $this->bot->withChatId($chatId);
            if ($photoUrl !== '') {
                $sentMsgId = $chat->sendPhoto($photoUrl, $caption, $threadId > 0 ? $threadId : null) ?? 0;
            } elseif ($text !== '') {
                $sentMsgId = $chat->sendMessageGetId($text, $threadId > 0 ? $threadId : null) ?? 0;
            }
        }

        if ($sentChatId !== '' && $sentMsgId > 0) {
            $meta->setMany([
                'wa_qr_tg_chat_id'   => $sentChatId,
                'wa_qr_tg_message_id' => (string) $sentMsgId,
                'wa_qr_tg_saved_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->_json($response, ['ok' => $sentMsgId > 0, 'chat_id' => $sentChatId, 'message_id' => $sentMsgId]);
    }

    private function _handleActive(ResponseInterface $response, MetaRepository $meta): ResponseInterface
    {
        $defaultChatId = Config::get('TELEGRAM_CHAT_ID') ?: Config::get('TG_CHAT_ID');
        $adminChatId   = Config::get('WA_ADMIN_TG_CHAT_ID') ?: Config::get('TG_ADMIN_ID', '169510539');

        $qrChatId = $meta->get('wa_qr_tg_chat_id', '') ?: $defaultChatId;
        $qrMsgId  = (int) $meta->get('wa_qr_tg_message_id', '0');

        $deleted = false;
        if ($qrChatId !== '' && $qrMsgId > 0) {
            $deleted = $this->bot->withChatId($qrChatId)->deleteMessage($qrMsgId);
        }

        $meta->setMany(['wa_qr_tg_chat_id' => '', 'wa_qr_tg_message_id' => '0', 'wa_qr_tg_saved_at' => '']);

        $sentActive = false;
        if ($adminChatId !== '') {
            $this->bot->withChatId($adminChatId)->sendMessage('WA: активен ✅');
            $sentActive = true;
        }

        return $this->_json($response, ['ok' => true, 'qr_deleted' => $deleted, 'wa_active_sent' => $sentActive]);
    }

    private function _json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
