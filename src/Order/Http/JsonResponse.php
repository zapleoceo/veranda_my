<?php

declare(strict_types=1);

namespace App\Order\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Tiny helper so every Action returns JSON in the same shape:
 *   {ok: true,  ...payload}
 *   {ok: false, error: "<reason>"}
 *
 * Keeps the Actions focused on the business work — they call
 * JsonResponse::ok($r, $data) or ::error($r, 'msg', 400) and we
 * never duplicate the encode + headers + status dance.
 */
final class JsonResponse
{
    public static function ok(ResponseInterface $r, array $data = []): ResponseInterface
    {
        $body = json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
        $r->getBody()->write($body !== false ? $body : '{"ok":true}');
        return $r->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public static function error(ResponseInterface $r, string $message, int $status = 500): ResponseInterface
    {
        $body = json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        $r->getBody()->write($body !== false ? $body : '{"ok":false}');
        return $r
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
