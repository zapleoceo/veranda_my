<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\Infrastructure\Config;
use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\Infrastructure\TelegramBotClient;
use App\OnlineOrder\Contracts\OrderNotifierInterface;

/**
 * Pings the staff Telegram chat about every new delivery order: who,
 * where, what, totals, payment + courier status — everything the
 * operator needs to act without opening Poster.
 *
 * Deliberately self-contained (reads its own config, builds its own
 * bot client): a missing TELEGRAM_BOT_TOKEN turns this into a no-op
 * instead of an exception, because notification must never be the
 * reason an already-created Poster order errors out to the customer.
 */
final class TelegramOrderNotifier implements OrderNotifierInterface
{
    public function notifyNewOrder(array $summary): void
    {
        try {
            $token  = trim(Config::get('TELEGRAM_BOT_TOKEN', ''));
            $chatId = trim(Config::get('ONLINEORDER_TG_CHAT_ID', '') ?: Config::get('TELEGRAM_CHAT_ID', ''));
            if ($token === '' || $chatId === '') {
                return; // not configured — silent no-op by design
            }

            $threadRaw = trim(Config::get('ONLINEORDER_TG_THREAD_ID', ''));
            $threadId  = is_numeric($threadRaw) ? (int)$threadRaw : null;

            $bot = new TelegramBotClient($token, new HttpClient(timeoutSeconds: 10), $chatId);
            $bot->sendMessage($this->render($summary), $threadId);
        } catch (\Throwable $e) {
            try { Logger::get()->warning('[onlineorder/tg] notify failed', ['err' => $e->getMessage()]); }
            catch (\Throwable $_) { error_log('[onlineorder/tg] notify failed: ' . $e->getMessage()); }
        }
    }

    private function render(array $s): string
    {
        $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fmt = static fn(int $n) => number_format($n, 0, '.', ' ');

        $lines = [];
        $lines[] = '🛵 <b>Новый заказ на доставку #' . (int)$s['order_id'] . '</b>';
        $lines[] = '👤 ' . $esc($s['name']) . ' — <code>' . $esc($s['phone']) . '</code>';
        $lines[] = '📍 ' . $esc($s['address']);
        $lines[] = '';
        foreach (($s['items'] ?? []) as $item) {
            $lines[] = '• ' . $esc($item);
        }
        $lines[] = '';
        $lines[] = '💰 Еда: <b>' . $fmt((int)$s['total_vnd']) . ' ₫</b>';

        $d = $s['delivery'] ?? null;
        if (is_array($d) && !empty($d['available'])) {
            $lines[] = sprintf(
                '🚚 Доставка: %s ₫ (%s%s%s) — платит клиент курьеру',
                $fmt((int)$d['fee_vnd']),
                $esc($d['provider']),
                isset($d['distance_km']) && $d['distance_km'] !== null ? ', ' . $d['distance_km'] . ' км' : '',
                isset($d['eta_minutes']) && $d['eta_minutes'] !== null ? ', ~' . (int)$d['eta_minutes'] . ' мин' : '',
            );
        } else {
            $lines[] = '🚚 Доставка: <b>согласовать с клиентом и вызвать курьера</b>';
        }

        $p = $s['payment'] ?? null;
        $lines[] = is_array($p)
            ? '💳 Оплата еды: QR-перевод, назначение <code>' . $esc($p['reference'] ?? '') . '</code> — <b>проверить поступление!</b>'
            : '💳 Оплата еды: QR не настроен — связаться с клиентом по оплате';

        $disp = $s['dispatch'] ?? null;
        if (is_array($disp)) {
            $lines[] = !empty($disp['tracking_id'])
                ? '🚕 Курьер вызван автоматически: ' . $esc($disp['provider'] ?? '') . ' #' . $esc($disp['tracking_id'])
                : '🚕 Автовызов курьера не удался: ' . $esc($disp['error'] ?? 'unknown') . ' — вызвать вручную';
        }

        return implode("\n", $lines);
    }
}
