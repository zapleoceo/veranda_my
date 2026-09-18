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
    /** Decoded image cap. html2canvas PNGs of the card are ~200–800 KB. */
    public const MAX_BYTES = 5 * 1024 * 1024;

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
            // Catch-all so any fatal still produces a proper JSON body.
            $this->log?->error('payday3 balance screenshot fatal', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
            ]);
            return JsonResponder::fromException($response, $e);
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
        $bytes = self::decodeImage($img);   // throws InvalidArgumentException → 400

        $mime   = self::sniffMime($bytes);
        $result = $this->tg->sendPhoto($bytes, $mime, 'Итоговый баланс');
        if (!($result['ok'] ?? false)) {
            $err = (string)($result['error'] ?? 'Telegram error');
            $this->log?->warning('payday3 balance screenshot failed', [
                'error'      => $err,
                'image_kb'   => intdiv(strlen($bytes), 1024),
                'mime'       => $mime,
            ]);
            // Telegram's own description (chat not found, too big…) is
            // operator-actionable and contains no secrets.
            return JsonResponder::error($response, 'Telegram: ' . $err, 502);
        }
        return JsonResponder::ok($response);
    }

    /**
     * data:image/(png|jpeg);base64,… → raw bytes, validated:
     * ≤ MAX_BYTES decoded, and getimagesizefromstring() must recognise a
     * real PNG or JPEG (not just trust the data-URL prefix).
     *
     * @throws \InvalidArgumentException
     */
    public static function decodeImage(string $dataUrl): string
    {
        if ($dataUrl === '' || !preg_match('#^data:image/(png|jpeg);base64,#', $dataUrl)) {
            throw new \InvalidArgumentException('Invalid image payload');
        }
        $b64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
        // Cheap pre-check before allocating the decoded copy (base64 = 4/3).
        if (strlen($b64) > intdiv(self::MAX_BYTES * 4, 3) + 4) {
            throw new \InvalidArgumentException('Изображение больше 5 МБ');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw new \InvalidArgumentException('base64_decode failed');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Изображение больше 5 МБ');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new \InvalidArgumentException('Это не PNG/JPEG изображение');
        }
        return $bytes;
    }

    /** MIME from the actual bytes, not from the client's data-URL prefix. */
    private static function sniffMime(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        return ($info !== false && ($info[2] ?? 0) === IMAGETYPE_JPEG) ? 'image/jpeg' : 'image/png';
    }
}
