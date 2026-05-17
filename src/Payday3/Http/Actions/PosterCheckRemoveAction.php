<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterCheckServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /payday3/api/poster/checks/{id}
 *
 * Removes the check and notifies Telegram with who-did-what. The
 * "who" string is composed from the session — auth middleware
 * stashed user_email and user_name there. Action keeps no business
 * logic; service handles error_code → exception + Telegram.
 */
final class PosterCheckRemoveAction
{
    public function __construct(private readonly PosterCheckServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return JsonResponder::error($response, 'Invalid transaction id.', 400);
        }
        $by = trim(
            (string)($_SESSION['user_name']  ?? '') . ' ' .
            (string)($_SESSION['user_email'] ?? '')
        );
        try {
            $res = $this->service->remove($id, $by);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, $res);
    }
}
