<?php
declare(strict_types=1);

function api_json_headers(bool $noCache = true): void {
  header('Content-Type: application/json; charset=utf-8');
  if ($noCache) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  }
}

function api_send_json(array $data, int $httpCode = 200): void {
  http_response_code($httpCode);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function api_ok(array $data = []): void {
  api_send_json(array_merge(['ok' => true], $data), 200);
}

function api_error(int $httpCode, string $message): void {
  api_send_json(['ok' => false, 'error' => $message], $httpCode);
}

function api_read_payload(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false) $raw = '';
  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  $looksJson = preg_match('/^\s*[\{\[]/', (string)$raw) === 1;
  if (strpos($ct, 'application/json') !== false || $looksJson) {
    $payload = json_decode($raw !== '' ? $raw : '[]', true);
    return is_array($payload) ? $payload : [];
  }
  return is_array($_POST ?? null) ? $_POST : [];
}

function api_normalize_e164_phone(string $raw): string {
  $digits = preg_replace('/\D+/', '', $raw);
  $digits = trim((string)$digits);
  if ($digits === '' || !preg_match('/^[1-9]\d{6,15}$/', $digits)) return '';
  return '+' . $digits;
}

function api_parse_datetime_local(string $value, DateTimeZone $tz): ?DateTimeImmutable {
  $v = trim($value);
  if ($v === '') return null;
  $dt = null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $v, $tz) ?: null;
  } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $v)) {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $v, $tz) ?: null;
  } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, $tz) ?: null;
  }
  if ($dt instanceof DateTimeImmutable) return $dt;
  try {
    return new DateTimeImmutable($v, $tz);
  } catch (\Throwable $e) {
    return null;
  }
}

