<?php

function employees_no_cache_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function employees_json_headers(): void {
    header('Content-Type: application/json; charset=utf-8');
    employees_no_cache_headers();
}

function employees_json_exit(array $payload, int $code = 200): void {
    employees_json_headers();
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function employees_csrf_ensure(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['employees_csrf'])) {
        $_SESSION['employees_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['employees_csrf'];
}

/**
 * CSRF gate for state-changing AJAX. Two distinct failure modes:
 *
 *   • Token mismatch with a *live* token — real CSRF or stale tab.
 *     Front-end shows the error and asks the user to refresh.
 *   • Session has no token at all (session was recycled — GC, restart,
 *     or new browser fingerprint). We MINT a fresh one and tell the
 *     client to retry with it via `new_csrf`. The user sees nothing.
 *
 * This was a real source of intermittent "CSRF failed" 403s — long-
 * lived tabs survived a session reset, the AJAX request authenticated
 * fine (still had user_email after a re-login or session reuse), but
 * the in-page token didn't match the new session's freshly-minted one.
 */
function employees_csrf_require(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        employees_json_exit(['ok' => false, 'error' => 'Сессия не активна, обновите страницу', 'needs_refresh' => true], 419);
    }
    $got      = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $existing = (string)($_SESSION['employees_csrf'] ?? '');

    // Session has no token at all → mint one, ask the client to retry
    // transparently with it. Auth is already verified upstream by
    // AuthMiddleware / Permissions::can(), so this can't be exploited.
    if ($existing === '') {
        $existing = bin2hex(random_bytes(16));
        $_SESSION['employees_csrf'] = $existing;
        employees_json_exit([
            'ok'          => false,
            'error'       => 'Токен обновлён, повторите действие',
            'new_csrf'    => $existing,
            'retry'       => true,
        ], 419);
    }

    if (!hash_equals($existing, $got)) {
        employees_json_exit([
            'ok'            => false,
            'error'         => 'CSRF mismatch — обновите страницу',
            'needs_refresh' => true,
        ], 419);
    }
}

