<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Infrastructure\Permissions;
use App\Payday3\Contracts\AuditLogInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Http\CurrentUser;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET  /payday3/api/settings  → current LocalSettings (client shape)
 * POST /payday3/api/settings  → validate + persist (ADMIN only)
 *
 * Saving is restricted to admins: the settings pick the Poster accounts
 * money is moved between and the Telegram chat that receives the audit
 * notes ("check deleted by X") — any payday user could re-point them.
 * Every save is written to payday_audit_log with a before/after diff.
 */
final class SettingsAction
{
    public function __construct(
        private readonly LocalSettingsRepositoryInterface $repo,
        private readonly AuditLogInterface                $audit,
    ) {}

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
        if (!Permissions::isAdmin()) {
            return JsonResponder::error($response, 'Изменять настройки может только администратор.', 403);
        }
        try {
            $before = $this->repo->load()->toClientPayload();
            $r      = $this->repo->save((array)$request->getParsedBody());
            if (!$r['ok']) return JsonResponder::error($response, (string)($r['error'] ?? 'save failed'), 400);
            $after  = $this->repo->load()->toClientPayload();
            $this->audit->record(CurrentUser::email(), 'settings.save', [
                'changes' => self::diff($before, $after),
            ]);
        } catch (\Throwable $e) {
            return JsonResponder::fromException($response, $e);
        }
        return JsonResponder::ok($response, $after);
    }

    /**
     * Flat "path → [old, new]" list of changed values.
     *
     * @return array<string, array{0:mixed, 1:mixed}>
     */
    public static function diff(array $before, array $after, string $prefix = ''): array
    {
        $out  = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $k) {
            $a = $before[$k] ?? null;
            $b = $after[$k]  ?? null;
            if (is_object($a)) $a = (array)$a;
            if (is_object($b)) $b = (array)$b;
            $path = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($a) && is_array($b) && !array_is_list($a) && !array_is_list($b)) {
                $out += self::diff($a, $b, $path);
            } elseif ($a !== $b) {
                $out[$path] = [$a, $b];
            }
        }
        return $out;
    }
}
