<?php

declare(strict_types=1);

namespace App\Schedule\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Tiny JSON-response helper shared by all schedule Actions.
 * Avoids re-implementing the same write+content-type dance.
 */
final class JsonResponder
{
    public function ok(ResponseInterface $response, array $data): ResponseInterface
    {
        return $this->write($response, ['ok' => true] + $data, 200);
    }

    public function fail(ResponseInterface $response, string $error, int $status = 400): ResponseInterface
    {
        return $this->write($response, ['ok' => false, 'error' => $error], $status);
    }

    /**
     * 423 Locked — page is being edited by someone else. Includes the
     * lock holder info so the JS banner can name the operator.
     */
    public function locked(ResponseInterface $response, ?array $lock): ResponseInterface
    {
        return $this->write($response, [
            'ok'     => false,
            'locked' => true,
            'lock'   => $lock,
            'error'  => 'Страница редактируется другим пользователем — изменения не сохранены.',
        ], 423);
    }

    public function write(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        );
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
