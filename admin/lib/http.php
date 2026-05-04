<?php

function admin_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function admin_json_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    admin_no_cache_headers();
}

function admin_json_exit(array $payload, int $code = 200): void
{
    admin_json_headers();
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function admin_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

