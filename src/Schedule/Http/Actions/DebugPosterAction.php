<?php

declare(strict_types=1);

namespace App\Schedule\Http\Actions;

use App\Classes\PosterAPI;
use App\Infrastructure\Config;
use App\Schedule\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /schedule?ajax=debug_poster — admin-only diagnostic that returns the
 * RAW access.getEmployees response and the set of keys observed across all
 * rows. Useful for verifying which (undocumented) field Poster uses to mark
 * dismissed employees in this account.
 *
 * The public v3 docs list only user_id / name / role_id / role_name / phone /
 * access_mask / user_type / last_in — but real responses often carry extra
 * fields. Open this URL once after deploy and check the `unique_keys` array
 * to confirm which dismissal indicator (if any) is present.
 */
final class DebugPosterAction
{
    public function __construct(private readonly JsonResponder $json) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (empty($_SESSION['user_permissions']['admin'] ?? null)) {
            return $this->json->fail($response, 'Forbidden', 403);
        }
        $token = Config::get('POSTER_API_TOKEN', '');
        if ($token === '') {
            return $this->json->fail($response, 'POSTER_API_TOKEN not configured', 500);
        }
        try {
            $api = new PosterAPI($token);
            $rows = $api->request('access.getEmployees', [], 'GET');
        } catch (\Throwable $e) {
            return $this->json->fail($response, $e->getMessage(), 502);
        }
        $keys = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                foreach (array_keys($r) as $k) $keys[$k] = true;
            }
        }
        return $this->json->ok($response, [
            'count'       => is_array($rows) ? count($rows) : 0,
            'unique_keys' => array_keys($keys),
            'sample'      => is_array($rows) ? array_slice($rows, 0, 3) : null,
            'raw'         => $rows,
        ]);
    }
}
