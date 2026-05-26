<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Single source of truth for "does the current user have permission X?".
 *
 * Strict by design: returns FALSE when $_SESSION['user_permissions'] isn't
 * a populated array. The old layout/sidebar/controller checks defaulted
 * to TRUE in that case ("permissive when unknown"), which let stale-perm
 * users slip past the page-level gate even after revocation.
 *
 * UserPermissionsService (Slim path) and auth_check.php (legacy path)
 * both populate $_SESSION['user_permissions'] before request handlers
 * run, so by the time anything calls Permissions::can() the perms ARE
 * loaded — making strict-default safe.
 *
 * Used by:
 *   • src/Views/layout.php — sidebar link visibility
 *   • src/Controllers/*    — page-level 403 gates
 *   • employees/index.php  — fail-fast before CSRF on every AJAX call
 *   • banya/roma/...       — same pattern
 */
final class Permissions
{
    public static function can(string $key): bool
    {
        $perms = $_SESSION['user_permissions'] ?? null;
        return is_array($perms) && !empty($perms[$key]);
    }

    /** True when the user has admin rights — implicit super-permission. */
    public static function isAdmin(): bool
    {
        return self::can('admin');
    }

    /**
     * HTML 403 + halt. For controllers that render pages.
     * Writes to the given response. Returns it (chainable in a return).
     */
    public static function denyHtml(\Psr\Http\Message\ResponseInterface $response): \Psr\Http\Message\ResponseInterface
    {
        $response->getBody()->write('Forbidden');
        return $response->withStatus(403)->withHeader('Content-Type', 'text/plain');
    }

    /**
     * JSON 403 + exit. For legacy non-Slim handlers (employees/index.php
     * AJAX dispatch, banya/roma model methods, etc.). Mirrors the shape
     * the front-end already expects: { ok: false, error: "Forbidden" }.
     */
    public static function denyJsonExit(string $message = 'Forbidden'): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            http_response_code(403);
        }
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
