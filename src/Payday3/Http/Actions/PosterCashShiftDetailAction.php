<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\PosterCashShiftServiceInterface;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /payday3/api/poster/cashshifts/{shiftId} */
final class PosterCashShiftDetailAction
{
    public function __construct(private readonly PosterCashShiftServiceInterface $service) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $shiftId = (string)($args['shiftId'] ?? '');
        try {
            $rows = $this->service->detail($shiftId);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, ['transactions' => $rows]);
    }
}
