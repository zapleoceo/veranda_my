<?php

declare(strict_types=1);

namespace App\PosterApp\Http\Actions;

use App\Order\Http\JsonResponse;
use App\PosterApp\Infrastructure\PosterAppToken;
use App\PosterApp\Services\WorkShiftService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /poster-app/api/shift-start
 *
 * Triggered by the POS widget on Poster.on('shiftOpen', …). Body:
 *   { poster_shift_id }
 *
 * Auth: bearer token from /login (Authorization: Bearer <token>).
 * The user id is derived from the token, NOT from the request body.
 */
final class WidgetShiftStartAction
{
    public function __construct(
        private readonly WorkShiftService $shifts,
        private readonly PosterAppToken   $token,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uid = $this->resolveUid($request);
        if ($uid === null) return JsonResponse::error($response, 'Unauthorized', 401);

        $b = $this->readJson($request) ?? [];
        $posterShiftId = isset($b['poster_shift_id']) && is_numeric($b['poster_shift_id'])
            ? (int)$b['poster_shift_id']
            : null;

        try {
            $shift = $this->shifts->ensureOpen($uid, $posterShiftId, WorkShiftService::SOURCE_POS_WIDGET);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }

        return JsonResponse::ok($response, ['shift' => $shift->toJson()]);
    }

    private function resolveUid(ServerRequestInterface $request): ?int
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!str_starts_with($auth, 'Bearer ')) return null;
        $tok = trim(substr($auth, 7));
        $uid = $this->token->verify($tok);
        return $uid;
    }

    private function readJson(ServerRequestInterface $request): ?array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed) return $parsed;
        $raw = (string)$request->getBody();
        if ($raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }
}
