<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\TelegramNotifierInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /payday3/api/balances/telegram
 *
 * Receives a base64 PNG of the balance card (rendered client-side
 * via html2canvas) and forwards it to the configured Telegram
 * chat/thread as a sendPhoto. Mirrors payday2's
 * `?ajax=poster_balances_telegram_screenshot`.
 *
 * Slim's BodyParsingMiddleware decodes the JSON before we run, so we
 * prefer getParsedBody() and only fall back to the raw stream when
 * (for whatever reason) the middleware didn't populate it.
 */
final class BalanceScreenshotAction
{
    public function __construct(
        private readonly TelegramNotifierInterface $tg,
        private readonly ?LoggerInterface          $log = null,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Big base64 payloads + libcurl upload can spike memory and
        // wall-time well past defaults. Lift them locally so a single
        // screenshot send doesn't push PHP-FPM into OOM / segfault —
        // when the worker dies, nginx returns an empty body and CF
        // serves a generic 502 (origin_bad_gateway), masking the real
        // cause.
        @ini_set('memory_limit', '256M');
        @set_time_limit(60);

        try {
            return $this->run($request, $response);
        } catch (\Throwable $e) {
            // Catch-all so any fatal still produces a proper JSON
            // body — the front-end can show the message instead of
            // falling back to "HTTP 502".
            $this->log?->error('payday3 balance screenshot fatal', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
    }

    private function run(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            $raw     = (string)$request->getBody();
            $payload = json_decode($raw, true);
        }
        $img = is_array($payload) ? (string)($payload['image'] ?? '') : '';
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
            $err = (string)($result['error'] ?? 'Telegram error');
            $this->log?->warning('payday3 balance screenshot failed', [
                'error'      => $err,
                'image_kb'   => intdiv(strlen($bytes), 1024),
                'mime'       => $mime,
            ]);
            return JsonResponder::error($response, $err, 502);
        }
        return JsonResponder::ok($response);
    }
}
