<?php

declare(strict_types=1);

namespace App\PosterApp\Http\Actions;

use App\Order\Http\JsonResponse;
use App\PosterApp\Infrastructure\PosterAppToken;
use App\PosterApp\Services\PinAuthService;
use App\PosterApp\Services\WorkShiftService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /poster-app/api/login
 *
 * Called by the POS widget on Poster.on('userLogin', …). Body:
 *   { poster_user_id, pin, name, admin? }
 *
 * Side effects:
 *   - Learn / refresh the bcrypt PIN hash for this user (so /neworder
 *     can authenticate them later from a regular browser).
 *   - Open a work shift if none is currently open for this user.
 *   - Mint a 12-hour token bound to poster_user_id and return it.
 *
 * The token goes back as JSON; the widget keeps it in memory and
 * forwards it on subsequent requests. No cookies, no cross-site
 * SameSite gymnastics.
 */
final class WidgetLoginAction
{
    public function __construct(
        private readonly PinAuthService    $pins,
        private readonly WorkShiftService  $shifts,
        private readonly PosterAppToken    $token,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $b = $this->readJson($request);
        if ($b === null) return JsonResponse::error($response, 'Bad JSON', 400);

        $uid   = (int)($b['poster_user_id'] ?? 0);
        $pin   = trim((string)($b['pin']  ?? ''));
        $name  = trim((string)($b['name'] ?? ''));
        $admin = (bool)($b['admin'] ?? false);
        if ($uid <= 0 || $pin === '') {
            return JsonResponse::error($response, 'poster_user_id and pin required', 400);
        }

        try {
            $this->pins->learnFromWidget($uid, $pin, $name, $admin);
            $shift = $this->shifts->ensureOpen($uid, null, WorkShiftService::SOURCE_POS_WIDGET);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, $e->getMessage(), 500);
        }

        return JsonResponse::ok($response, [
            'token' => $this->token->mint($uid),
            'user'  => [
                'poster_user_id' => $uid,
                'name'           => $name,
                'admin'          => $admin,
            ],
            'shift' => $shift->toJson(),
        ]);
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
