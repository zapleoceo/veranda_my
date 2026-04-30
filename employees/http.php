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

function employees_csrf_require(): void {
    $expected = employees_csrf_ensure();
    if ($expected === '') {
        employees_json_exit(['ok' => false, 'error' => 'CSRF not available'], 500);
    }
    $got = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals($expected, $got)) {
        employees_json_exit(['ok' => false, 'error' => 'CSRF failed'], 403);
    }
}

