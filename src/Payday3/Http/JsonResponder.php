<?php

declare(strict_types=1);

namespace App\Payday3\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Standardised JSON envelope for every payday3 API endpoint.
 * Centralising this lets every action stay one screen long and keeps
 * the wire format consistent: {ok: bool, data?: ..., error?: string}.
 */
final class JsonResponder
{
    public static function ok(ResponseInterface $res, mixed $data = null, int $status = 200): ResponseInterface
    {
        $body = $data === null ? ['ok' => true] : ['ok' => true, 'data' => $data];
        return self::write($res, $body, $status);
    }

    public static function error(ResponseInterface $res, string $message, int $status = 400): ResponseInterface
    {
        return self::write($res, ['ok' => false, 'error' => $message], $status);
    }

    /** 429 with Retry-After header, sourced from TooManyRequestsException. */
    public static function tooManyRequests(ResponseInterface $res, string $message, int $retryAfter): ResponseInterface
    {
        return self::write($res, ['ok' => false, 'error' => $message, 'retry_after' => $retryAfter], 429)
            ->withHeader('Retry-After', (string)$retryAfter);
    }

    private static function write(ResponseInterface $res, array $body, int $status): ResponseInterface
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $res->getBody()->write($json === false ? '{"ok":false,"error":"json_encode failed"}' : $json);
        return $res
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
