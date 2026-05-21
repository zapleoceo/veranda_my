<?php

declare(strict_types=1);

namespace App\PosterApp\Http\Actions;

use App\Order\Http\JsonResponse;
use App\PosterApp\Infrastructure\PosterAppToken;
use App\PosterApp\Services\WorkShiftService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /poster-app/api/shift-end
 *
 * Triggered by the POS widget on Poster.on('shiftClose', …), OR by
 * the operator explicitly tapping "End shift" in our widget UI.
 * Body (all optional):
 *   { poster_shift_id }
 *
 * Auth: bearer token. The user id from the token IS the
 * authoritative owner of the shift to close — we close that user's
 * open shift regardless of whether poster_shift_id was provided.
 * (poster_shift_id is honoured only as a secondary close-target
 * when the event arrives for a user other than the token-bound one.)
 */
final class WidgetShiftEndAction
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
            $this->shifts->closeForUser($uid);
            if ($posterShiftId !== null) {
                // Belt-and-braces for the case where the shift wasn't
                // tied to this user (e.g. event delivered after token
                // expiry). Close by poster_shift_id too.
                $this->shifts->closeByPosterShiftId($posterShiftId);
            }
        } catch (\Throwable $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }

        return JsonResponse::ok($response, []);
    }

    private function resolveUid(ServerRequestInterface $request): ?int
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!str_starts_with($auth, 'Bearer ')) return null;
        return $this->token->verify(trim(substr($auth, 7)));
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
