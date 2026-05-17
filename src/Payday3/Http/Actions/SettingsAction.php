<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET  /payday3/api/settings  → current LocalSettings (client shape)
 * POST /payday3/api/settings  → validate + persist
 *
 * Single action serves both verbs of the resource — payday2's modal
 * called two different ajax endpoints; we collapse them.
 */
final class SettingsAction
{
    public function __construct(private readonly LocalSettingsRepositoryInterface $repo) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $request->getMethod() === 'POST'
            ? $this->save($request, $response)
            : $this->load($response);
    }

    private function load(ResponseInterface $response): ResponseInterface
    {
        return JsonResponder::ok($response, $this->repo->load()->toClientPayload());
    }

    private function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $r    = $this->repo->save($body);
        if (!$r['ok']) return JsonResponder::error($response, (string)($r['error'] ?? 'save failed'), 400);
        return JsonResponder::ok($response, $this->repo->load()->toClientPayload());
    }
}
