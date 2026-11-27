<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Http\JsonResponder;
use App\Payday3\Services\BalanceShotStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/balances/shots/{name}
 *   → the stored PNG/JPEG itself. The name is validated against the
 *     store's own pattern, so no path can escape the shots folder.
 */
final class BalanceShotFileAction
{
    public function __construct(private readonly BalanceShotStore $store) {}

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $name  = (string)($args['name'] ?? '');
        $bytes = $this->store->read($name);
        if ($bytes === null) {
            return JsonResponder::error($response, 'Снимок не найден.', 404);
        }
        $response->getBody()->write($bytes);
        return $response
            ->withHeader('Content-Type', str_ends_with($name, '.jpg') ? 'image/jpeg' : 'image/png')
            ->withHeader('Cache-Control', 'private, max-age=3600');
    }
}
