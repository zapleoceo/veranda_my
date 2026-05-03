<?php
declare(strict_types=1);

function tr3_api_tg_state_create(array $ctx): void {
  api_json_headers(true);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed');

  $db = $ctx['db'] ?? null;
  if (!($db instanceof \App\Classes\Database)) api_error(500, 'DB не настроена');

  $payload = api_read_payload();

  $tableNum = trim((string)($payload['table_num'] ?? ''));
  $guests = (int)($payload['guests'] ?? 0);
  $start = trim((string)($payload['start'] ?? ''));
  if ($tableNum === '' || !preg_match('/^\d+$/', $tableNum)) api_error(400, 'Некорректный номер стола');
  if ($guests <= 0 || $guests > 99) api_error(400, 'Некорректное кол-во гостей');

  $scriptBase = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api.php'));
  $sourcePage = trim((string)($payload['source_page'] ?? $scriptBase));
  if ($start === '' || mb_strlen($start) > 40) api_error(400, 'Некорректное время');

  $tgUserBot = trim((string)($_ENV['TABLE_RESERVATION_TG_BOT_USERNAME'] ?? $_ENV['TELEGRAM_BOT_USERNAME'] ?? $_ENV['TG_BOT_USERNAME'] ?? ''));
  if ($tgUserBot === '') {
    $token = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
    if ($token !== '') {
      try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$token}/getMe");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = $resp ? json_decode($resp, true) : null;
        if (is_array($data) && !empty($data['ok']) && is_array($data['result'] ?? null)) {
          $u = trim((string)($data['result']['username'] ?? ''));
          if ($u !== '') $tgUserBot = $u;
        }
      } catch (\Throwable $e) {}
    }
  }
  if ($tgUserBot === '') api_error(500, 'Не задан username бота Telegram');

  $code = bin2hex(random_bytes(9));
  $createdAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
  $expiresAt = (new DateTimeImmutable('now'))->modify('+30 minutes')->format('Y-m-d H:i:s');

  $t = $db->t('table_reservation_tg_states');
  $pdo = $db->getPdo();
  $pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (
    code VARCHAR(40) PRIMARY KEY,
    payload_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    tg_user_id BIGINT NULL,
    tg_username VARCHAR(64) NULL,
    tg_name VARCHAR(128) NULL,
    KEY idx_expires_at (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($payloadJson === false) $payloadJson = '{}';

  $db->query("INSERT INTO {$t} (code, payload_json, created_at, expires_at) VALUES (?, ?, ?, ?)", [$code, $payloadJson, $createdAt, $expiresAt]);

  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $returnUrl = ($host !== '' ? ($scheme . '://' . $host) : '') . '/' . ltrim($sourcePage, '/') . '?tg_state=' . rawurlencode($code);
  $botUrl = 'https://t.me/' . rawurlencode($tgUserBot) . '?start=' . rawurlencode($code);

  api_send_json(['ok' => true, 'code' => $code, 'bot_url' => $botUrl, 'return_url' => $returnUrl], 200);
}

function tr3_api_wa_state_create(array $ctx): void {
  api_json_headers(true);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed');

  $db = $ctx['db'] ?? null;
  if (!($db instanceof \App\Classes\Database)) api_error(500, 'DB не настроена');

  $payload = api_read_payload();

  $I18N = is_array($ctx['I18N'] ?? null) ? $ctx['I18N'] : [];
  $defaultLang = (string)($ctx['lang'] ?? 'ru');
  $supported = is_array($ctx['supportedLangs'] ?? null) ? $ctx['supportedLangs'] : ['ru', 'en', 'vi'];
  $langIn = strtolower(trim((string)($payload['lang'] ?? '')));
  $userLang = in_array($langIn, $supported, true) ? $langIn : $defaultLang;
  $trFor = function (string $key) use ($I18N, $userLang): string {
    return isset($I18N[$userLang][$key]) ? (string)$I18N[$userLang][$key] : $key;
  };

  $waSecret = trim((string)($_ENV['WA_NODE_SECRET'] ?? ($_ENV['WA_BRIDGE_SECRET'] ?? '')));
  if ($waSecret === '') api_error(500, $trFor('err_wa_not_configured'));

  $tableNum = trim((string)($payload['table_num'] ?? ''));
  $guests = (int)($payload['guests'] ?? 0);
  $start = trim((string)($payload['start'] ?? ''));
  $phone = trim((string)($payload['phone'] ?? ''));
  $scriptBase = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api.php'));
  $sourcePage = trim((string)($payload['source_page'] ?? $scriptBase));

  if ($tableNum === '' || !preg_match('/^\d+$/', $tableNum)) api_error(400, 'Некорректный номер стола');
  if ($guests <= 0 || $guests > 99) api_error(400, 'Некорректное кол-во гостей');
  if ($start === '' || mb_strlen($start) > 40) api_error(400, 'Некорректное время');

  $phoneNorm = api_normalize_e164_phone($phone);
  if ($phoneNorm === '') api_error(400, $trFor('phone_invalid'));

  if (function_exists('wa_bridge_is_available') && !wa_bridge_is_available()) {
    api_error(503, $trFor('wa_auth_unavailable'));
  }

  $code = bin2hex(random_bytes(9));
  $createdAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
  $expiresAt = (new DateTimeImmutable('now'))->modify('+30 minutes')->format('Y-m-d H:i:s');

  $t = $db->t('table_reservation_wa_states');
  $pdo = $db->getPdo();
  $pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (
    code VARCHAR(40) PRIMARY KEY,
    phone VARCHAR(64) NOT NULL,
    payload_json TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    KEY idx_expires_at (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $payload['whatsapp_phone'] = $phoneNorm;
  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($payloadJson === false) $payloadJson = '{}';
  $db->query("INSERT INTO {$t} (code, phone, payload_json, created_at, expires_at) VALUES (?, ?, ?, ?, ?)", [$code, $phoneNorm, $payloadJson, $createdAt, $expiresAt]);

  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $returnUrl = ($host !== '' ? ($scheme . '://' . $host) : '') . '/' . ltrim($sourcePage, '/') . '?wa_state=' . rawurlencode($code);

  $msg = "Подтвердите WhatsApp номер:\n" . $returnUrl;
  $sent = wa_bridge_send($phoneNorm, $msg);
  if (!$sent) api_error(500, $trFor('err_wa_send_failed'));

  api_send_json(['ok' => true, 'code' => $code, 'return_url' => $returnUrl], 200);
}

function tr3_api_tg_state_get(array $ctx): void {
  api_json_headers(true);

  $db = $ctx['db'] ?? null;
  if (!($db instanceof \App\Classes\Database)) api_error(500, 'DB не настроена');

  $code = trim((string)($_GET['code'] ?? ''));
  if ($code === '' || !preg_match('/^[a-f0-9]{8,40}$/', $code)) api_error(400, 'Bad request');

  $t = $db->t('table_reservation_tg_states');
  try {
    $row = $db->query("SELECT payload_json, expires_at, used_at, tg_user_id, tg_username, tg_name FROM {$t} WHERE code = ? LIMIT 1", [$code])->fetch();
  } catch (\Throwable $e) {
    $row = false;
  }
  if (!$row || !is_array($row)) api_error(404, 'Not found');

  $usedAt = (string)($row['used_at'] ?? '');
  if ($usedAt !== '') api_error(410, 'Expired');

  $expiresAt = (string)($row['expires_at'] ?? '');
  $expTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
  if ($expTs === false || $expTs < time()) {
    $db->query("DELETE FROM {$t} WHERE code = ?", [$code]);
    api_error(410, 'Expired');
  }

  $db->query("UPDATE {$t} SET used_at = ? WHERE code = ?", [(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $code]);
  $payloadJson = (string)($row['payload_json'] ?? '{}');
  $payload = json_decode($payloadJson, true);
  if (!is_array($payload)) $payload = [];
  $tgUserId = (int)($row['tg_user_id'] ?? 0);
  $tgUsername = trim((string)($row['tg_username'] ?? ''));
  $tgName = trim((string)($row['tg_name'] ?? ''));
  api_send_json(['ok' => true, 'payload' => $payload, 'tg' => ['user_id' => $tgUserId, 'username' => $tgUsername, 'name' => $tgName]], 200);
}

function tr3_api_wa_state_get(array $ctx): void {
  api_json_headers(true);

  $db = $ctx['db'] ?? null;
  if (!($db instanceof \App\Classes\Database)) api_error(500, 'DB не настроена');

  $code = trim((string)($_GET['code'] ?? ''));
  if ($code === '' || !preg_match('/^[a-f0-9]{8,40}$/', $code)) api_error(400, 'Bad request');

  $t = $db->t('table_reservation_wa_states');
  try {
    $row = $db->query("SELECT payload_json, phone, expires_at, used_at FROM {$t} WHERE code = ? LIMIT 1", [$code])->fetch();
  } catch (\Throwable $e) {
    $row = false;
  }
  if (!$row || !is_array($row)) api_error(404, 'Not found');

  $usedAt = (string)($row['used_at'] ?? '');
  if ($usedAt !== '') api_error(410, 'Expired');

  $expiresAt = (string)($row['expires_at'] ?? '');
  $expTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
  if ($expTs === false || $expTs < time()) {
    $db->query("DELETE FROM {$t} WHERE code = ?", [$code]);
    api_error(410, 'Expired');
  }

  $db->query("UPDATE {$t} SET used_at = ? WHERE code = ?", [(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $code]);
  $payloadJson = (string)($row['payload_json'] ?? '{}');
  $payload = json_decode($payloadJson, true);
  if (!is_array($payload)) $payload = [];
  $phone = trim((string)($row['phone'] ?? ''));
  api_send_json(['ok' => true, 'payload' => $payload, 'phone' => $phone], 200);
}

