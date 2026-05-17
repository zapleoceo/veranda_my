<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\TelegramNotifierInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /payday3/api/balances/telegram
 *
 * Receives a base64 PNG of the balance card (rendered client-side
 * via html2canvas) and forwards it to the configured Telegram
 * chat/thread as a sendPhoto. Mirrors payday2's
 * `?ajax=poster_balances_telegram_screenshot`.
 */
final class BalanceScreenshotAction
{
    public function __construct(private readonly TelegramNotifierInterface $tg) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw     = (string)$request->getBody();
        $payload = json_decode($raw, true);
        $img     = is_array($payload) ? (string)($payload['image'] ?? '') : '';
        if ($img === '' || !preg_match('#^data:image/(png|jpeg);base64,#', $img, $m)) {
            return JsonResponder::error($response, 'Invalid image payload', 400);
        }
        $mime  = 'image/' . ($m[1] === 'jpeg' ? 'jpeg' : 'png');
        $bytes = base64_decode(substr($img, strpos($img, ',') + 1) ?: '', true);
        if ($bytes === false || $bytes === '') {
            return JsonResponder::error($response, 'base64_decode failed', 400);
        }
        $result = $this->tg->sendPhoto($bytes, $mime, 'Итоговый баланс');
        if (!($result['ok'] ?? false)) {
            return JsonResponder::error($response, $result['error'] ?? 'Telegram error', 502);
        }
        return JsonResponder::ok($response);
    }
}
