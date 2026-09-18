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

    /** Shown instead of raw exception text (SQLSTATE, IMAP, Poster params…). */
    public const GENERIC_ERROR   = 'Внутренняя ошибка сервера. Подробности записаны в лог.';
    public const UPSTREAM_ERROR  = 'Ошибка внешнего сервиса (Poster / почта / Telegram). Подробности записаны в лог.';

    /**
     * Single exception → JSON mapping for every payday3 action.
     *
     *   TooManyRequestsException                 → 429 + Retry-After
     *   InvalidArgumentException / DomainException → 400 with the message
     *       (these are validation / business-rule messages written for
     *       the operator)
     *   anything else → error_log with class, message and location, and a
     *       generic Russian message with $status (500 by default, 502 for
     *       upstream failures) — raw text never reaches the browser.
     */
    public static function fromException(ResponseInterface $res, \Throwable $e, int $status = 500, string $context = 'payday3'): ResponseInterface
    {
        if ($e instanceof TooManyRequestsException) {
            return self::tooManyRequests($res, $e->getMessage(), $e->retryAfter);
        }
        if ($e instanceof \InvalidArgumentException || $e instanceof \DomainException) {
            return self::error($res, $e->getMessage(), 400);
        }
        error_log(sprintf(
            '[%s] %s: %s @ %s:%d',
            $context, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(),
        ));
        return self::error($res, $status === 502 ? self::UPSTREAM_ERROR : self::GENERIC_ERROR, $status);
    }

    /**
     * Map domain objects to their wire shape — the "list → toJsonShape"
     * line every links/mail/finance response used to spell out.
     *
     * @param iterable<object> $items objects exposing toJsonShape(): array
     * @return list<array<string,mixed>>
     */
    public static function shapes(iterable $items): array
    {
        $out = [];
        foreach ($items as $i) $out[] = $i->toJsonShape();
        return $out;
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
