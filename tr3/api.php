<?php

/*
TR3 API — Developer Note

This file is the Controller for TR3 (all AJAX endpoints).

MVC / Separation of Responsibility:
- Controller (this file): validates input, coordinates services, returns JSON. Keep it thin.
- Models/Services: all business logic + integrations (Poster/DB/Telegram/WhatsApp) must be extracted into service classes with clear interfaces.
- View: /tr3/index.php (UI only).

Rules:
- One responsibility per function/class. Avoid huge “god controllers”.
- No UI concerns here (no HTML rendering).
- No dependency on previous versions/pages. TR3 must stay independent.
*/

$cfg = require __DIR__ . '/i18n.php';
$supportedLangs = is_array($cfg['supported'] ?? null) ? $cfg['supported'] : ['ru', 'en', 'vi'];
$I18N = is_array($cfg['i18n'] ?? null) ? $cfg['i18n'] : [];

$lang = null;
if (isset($_GET['lang'])) {
  $candidate = strtolower(trim((string)$_GET['lang']));
  if (in_array($candidate, $supportedLangs, true)) {
    $lang = $candidate;
    setcookie('links_lang', $lang, [
      'expires' => time() + 31536000,
      'path' => '/',
      'samesite' => 'Lax'
    ]);
  }
}
if ($lang === null) {
  $cookieLang = strtolower(trim((string)($_COOKIE['links_lang'] ?? '')));
  if (in_array($cookieLang, $supportedLangs, true)) $lang = $cookieLang;
}
if ($lang === null) $lang = 'ru';
if (!isset($I18N[$lang])) $lang = 'ru';

function tr(string $key): string {
  global $I18N, $lang;
  return isset($I18N[$lang][$key]) ? (string)$I18N[$lang][$key] : $key;
}

if (file_exists(__DIR__ . '/../.env')) {
  $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  foreach ($lines as $line) {
    $t = trim($line);
    if ($t === '' || strpos($t, '#') === 0) continue;
    if (strpos($t, '=') === false) continue;
    [$name, $value] = explode('=', $line, 2);
    $_ENV[$name] = trim($value);
  }
}

$displayTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($displayTzName === '' || !in_array($displayTzName, timezone_identifiers_list(), true)) {
  $displayTzName = 'Asia/Ho_Chi_Minh';
}
$apiTzName = trim((string)($_ENV['POSTER_API_TIMEZONE'] ?? ''));
if ($apiTzName === '' || !in_array($apiTzName, timezone_identifiers_list(), true)) {
  $apiTzName = $displayTzName;
}
date_default_timezone_set($apiTzName);

require_once __DIR__ . '/../src/classes/PosterAPI.php';
require_once __DIR__ . '/../src/classes/TelegramBot.php';
require_once __DIR__ . '/../src/classes/ReservationTelegram.php';
require_once __DIR__ . '/../reservations/tg_send_manager.php';

function wa_bridge_send(string $phone, string $text, string $imageUrl = ''): bool {
  $host = trim((string)($_ENV['WA_HTTP_HOST'] ?? '127.0.0.1'));
  $portRaw = trim((string)($_ENV['WA_HTTP_PORT'] ?? '3210'));
  $port = is_numeric($portRaw) ? (int)$portRaw : 3210;
  if ($port <= 0 || $port > 65535) $port = 3210;

  $secret = trim((string)($_ENV['WA_NODE_SECRET'] ?? ($_ENV['WA_BRIDGE_SECRET'] ?? '')));
  if ($secret === '') return false;

  $url = 'http://' . $host . ':' . $port . '/send';

  $payload = [
    'phone' => $phone,
    'text' => $text,
  ];
  if ($imageUrl !== '') $payload['image_url'] = $imageUrl;

  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Accept: application/json',
    'X-WA-BRIDGE: ' . $secret,
  ]);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 10);
  $resp = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false || $httpCode < 200 || $httpCode >= 300) return false;
  $body = trim((string)$resp);
  if ($body === '') return true;
  $j = json_decode($body, true);
  if (!is_array($j)) return true;
  if (array_key_exists('ok', $j)) return !empty($j['ok']);
  if (array_key_exists('sent', $j)) return !empty($j['sent']);
  if (array_key_exists('success', $j)) return !empty($j['success']);
  return true;
}

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
$now = new DateTimeImmutable('now', new DateTimeZone($displayTzName));
$roundedNow = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
$m = (int)$roundedNow->format('i');
$add = (15 - ($m % 15)) % 15;
if ($add > 0) $roundedNow = $roundedNow->modify('+' . $add . ' minutes');
$defaultResDateLocal = $roundedNow->format('Y-m-d\TH:i');

$hallIdForSettings = 2;
$allowedSchemeNums = null;
$soonBookingHours = 2;
$tableCapsByNum = [
  '1' => 8, '2' => 8, '3' => 8,
  '4' => 5, '5' => 5, '6' => 5, '7' => 5, '8' => 5,
  '9' => 8,
  '10' => 2, '11' => 2, '12' => 2, '13' => 2,
  '14' => 3, '15' => 3, '16' => 3,
  '17' => 5, '18' => 5, '19' => 5, '20' => 5, '21' => 5,
  '22' => 15,
];

try {
  $dbHost = trim((string)($_ENV['DB_HOST'] ?? ''));
  $dbName = trim((string)($_ENV['DB_NAME'] ?? ''));
  $dbUser = trim((string)($_ENV['DB_USER'] ?? ''));
  $dbPass = (string)($_ENV['DB_PASS'] ?? '');
  $dbSuffix = trim((string)($_ENV['DB_TABLE_SUFFIX'] ?? ''));

  if ($dbHost !== '' && $dbName !== '' && $dbUser !== '') {
    require_once __DIR__ . '/../src/classes/Database.php';
    require_once __DIR__ . '/../src/classes/MetaRepository.php';
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $dbSuffix);
    $metaRepo = new \App\Classes\MetaRepository($db);
    $key = 'reservations_allowed_scheme_nums_hall_' . $hallIdForSettings;
    $capsKey = 'reservations_table_caps_hall_' . $hallIdForSettings;
    $soonKey = 'reservations_soon_booking_hours';
    $workdayKey = 'reservations_latest_workday';
    $weekendKey = 'reservations_latest_weekend';
    $vals = $metaRepo->getMany([$key, $soonKey, $workdayKey, $weekendKey]);
    $stored = array_key_exists($key, $vals) ? trim((string)$vals[$key]) : '';
    
    $latestWorkday = array_key_exists($workdayKey, $vals) ? trim((string)$vals[$workdayKey]) : '21:00';
    $latestWeekend = array_key_exists($weekendKey, $vals) ? trim((string)$vals[$weekendKey]) : '22:00';
    if ($latestWorkday === '') $latestWorkday = '21:00';
    if ($latestWeekend === '') $latestWeekend = '22:00';
    if ($stored !== '') {
      $decoded = json_decode($stored, true);
      $tmp = [];
      if (is_array($decoded)) {
        foreach ($decoded as $v) {
          $n = (int)$v;
          if ($n >= 1 && $n <= 500) $tmp[(string)$n] = true;
        }
      } else {
        foreach (explode(',', $stored) as $part) {
          $part = trim($part);
          if ($part === '' || !preg_match('/^\d+$/', $part)) continue;
          $n = (int)$part;
          if ($n >= 1 && $n <= 500) $tmp[(string)$n] = true;
        }
      }
      $allowedSchemeNums = array_values(array_keys($tmp));
      usort($allowedSchemeNums, fn($a, $b) => (int)$a <=> (int)$b);
    }
    $soonStored = array_key_exists($soonKey, $vals) ? trim((string)$vals[$soonKey]) : '';
    if ($soonStored !== '' && is_numeric($soonStored)) {
      $soonBookingHours = max(0, min(24, (int)$soonStored));
    }
    $capsVals = $metaRepo->getMany([$capsKey]);
    $capsStored = array_key_exists($capsKey, $capsVals) ? trim((string)$capsVals[$capsKey]) : '';
    if ($capsStored !== '') {
      $decoded = json_decode($capsStored, true);
      if (is_array($decoded)) {
        foreach ($decoded as $k => $v) {
          $k = trim((string)$k);
          if (!preg_match('/^\d+$/', $k)) continue;
          $n = (int)$k;
          if ($n < 1 || $n > 500) continue;
          $c = (int)$v;
          if ($c < 0) $c = 0;
          if ($c > 999) $c = 999;
          $tableCapsByNum[(string)$n] = $c;
        }
      }
    }
  }
} catch (\Throwable $e) {
  $allowedSchemeNums = null;
}

$ajax = (string)($_GET['ajax'] ?? '');

if ($ajax === 'bootstrap') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  $minPreorderPerGuest = 100000;
  try {
    $dbHost = trim((string)($_ENV['DB_HOST'] ?? ''));
    $dbName = trim((string)($_ENV['DB_NAME'] ?? ''));
    $dbUser = trim((string)($_ENV['DB_USER'] ?? ''));
    if ($dbHost !== '' && $dbName !== '' && $dbUser !== '') {
      $vals = $metaRepo->getMany(['preorder_min_per_guest_vnd']);
      $stored = array_key_exists('preorder_min_per_guest_vnd', $vals) ? trim((string)$vals['preorder_min_per_guest_vnd']) : '';
      if ($stored !== '' && is_numeric($stored)) $minPreorderPerGuest = max(0, (int)$stored);
    }
  } catch (\Throwable $e) {}
  echo json_encode([
    'ok' => true,
    'lang' => $lang,
    'locale' => ($lang === 'ru') ? 'ru-RU' : ($lang === 'vi' ? 'vi-VN' : 'en-US'),
    'str' => $I18N[$lang] ?? [],
    'i18n_all' => $I18N,
    'defaultResDateLocal' => $defaultResDateLocal,
    'allowedTableNums' => $allowedSchemeNums,
    'tableCapsByNum' => $tableCapsByNum,
    'soonBookingHours' => $soonBookingHours,
    'latestWorkday' => $latestWorkday,
    'latestWeekend' => $latestWeekend,
    'minPreorderPerGuest' => $minPreorderPerGuest,
    'apiBase' => '/tr3/api.php',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($ajax === 'log_js') {
  header('Content-Type: application/json; charset=utf-8');
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  if (is_array($j)) {
    $logFile = __DIR__ . '/../js_debug.log';
    $entry = date('Y-m-d H:i:s') . ' | IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' | MSG: ' . ($j['msg'] ?? '') . ' | DATA: ' . json_encode($j['data'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
  }
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($ajax === 'free_tables') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  if ($posterToken === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $dateReservation = trim((string)($_GET['date_reservation'] ?? ''));
  $duration = (int)($_GET['duration'] ?? 0);
  $guests = (int)($_GET['guests_count'] ?? 0);
  $spotId = (int)($_GET['spot_id'] ?? 1);
  $hallId = 2;
  $allowed = $allowedSchemeNums;

  $displayTz = new DateTimeZone($displayTzName);
  $nowDisplay = new DateTimeImmutable('now', $displayTz);
  $dtDisplay = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateReservation, $displayTz);
  if ($dtDisplay !== false) {
    if ($dtDisplay->format('Y-m-d') === $nowDisplay->format('Y-m-d')) {
      $dtDisplay = $nowDisplay->modify('+5 minutes');
    }
  }
  if ($dtDisplay === false) {
    try { $dtDisplay = new DateTimeImmutable($dateReservation, $displayTz); } catch (\Throwable $e) { $dtDisplay = false; }
  }
  if ($dtDisplay === false || $dateReservation === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректная дата'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($duration < 1800) $duration = 7200;
  if ($guests <= 0) $guests = 2;
  if ($spotId <= 0) $spotId = 1;

  $api = new \App\Classes\PosterAPI($posterToken);
  try {
    $tablesResp = $api->request('spots.getTableHallTables', [
      'spot_id' => $spotId,
      'hall_id' => $hallId,
      'without_deleted' => 1,
    ], 'GET');
    $tablesResp = is_array($tablesResp) ? $tablesResp : [];
    $allowedSet = is_array($allowed) ? array_fill_keys(array_map('strval', $allowed), true) : null;
    $schemeById = [];
    $allAllowedNums = [];
    foreach ($tablesResp as $trRow) {
      if (!is_array($trRow)) continue;
      $id = trim((string)($trRow['table_id'] ?? ''));
      if ($id === '') continue;
      $num = trim((string)($trRow['table_num'] ?? ''));
      $title = trim((string)($trRow['table_title'] ?? ''));
      $scheme = '';
      if (preg_match('/^\d+$/', $title)) $scheme = $title;
      elseif (preg_match('/^\d+$/', $num)) $scheme = $num;
      if ($scheme === '') continue;
      $sStr = (string)$scheme;
      if (is_array($allowedSet) && !isset($allowedSet[$sStr])) continue;
      $schemeById[$id] = $sStr;
      $allAllowedNums[$sStr] = true;
    }

    $busyTableIds = [];
    $isToday = $dtDisplay->format('Y-m-d') === $nowDisplay->format('Y-m-d');
    if ($isToday) {
      try {
        $openTxs = $api->request('dash.getTransactions', ['status' => 1], 'GET');
        if (is_array($openTxs)) {
          foreach ($openTxs as $tx) {
            if (isset($tx['table_id'])) $busyTableIds[(int)$tx['table_id']] = true;
          }
        }
        if (!$busyTableIds) {
          $txResp = $api->request('transactions.getTransactions', [
            'date_from' => $nowDisplay->format('Y-m-d'),
            'date_to' => $nowDisplay->format('Y-m-d'),
            'per_page' => 1000,
            'page' => 1,
          ], 'GET');
          if (is_array($txResp) && isset($txResp['data']) && is_array($txResp['data'])) {
            foreach ($txResp['data'] as $row) {
              if (!is_array($row)) continue;
              $tid = isset($row['table_id']) ? (int)$row['table_id'] : 0;
              if ($tid > 0) {
                $dateClose = trim((string)($row['date_close'] ?? ''));
                $payType = (int)($row['pay_type'] ?? -1);
                if ($dateClose === '' || $payType === 0) $busyTableIds[$tid] = true;
              }
            }
          }
        }
      } catch (\Throwable $e) {
      }
    }
    $occupiedNowNums = [];
    if ($busyTableIds) {
      foreach (array_keys($busyTableIds) as $tId) {
        $k = (string)$tId;
        if (isset($schemeById[$k])) $occupiedNowNums[$schemeById[$k]] = true;
      }
      $occupiedNowNums = array_values(array_keys($occupiedNowNums));
      usort($occupiedNowNums, fn($a, $b) => (int)$a <=> (int)$b);
    } else {
      $occupiedNowNums = [];
    }

    $nums = [];
    foreach (array_keys($allAllowedNums) as $n) {
      if ($isToday && in_array($n, $occupiedNowNums, true)) continue;
      $nums[$n] = true;
    }
    $filtered = [];
    foreach ($tablesResp as $trRow) {
      if (!is_array($trRow)) continue;
      $id = trim((string)($trRow['table_id'] ?? ''));
      if ($id === '') continue;
      $scheme = isset($schemeById[$id]) ? $schemeById[$id] : '';
      if ($scheme === '' || !isset($nums[$scheme])) continue;
      $filtered[] = $trRow;
    }

    $busyReasons = [];
    if ($isToday) {
      foreach ($occupiedNowNums as $n) {
        $s = (string)$n;
        if (!isset($busyReasons[$s])) $busyReasons[$s] = [];
        if (!in_array('occupied_now', $busyReasons[$s], true)) $busyReasons[$s][] = 'occupied_now';
      }
    }

    echo json_encode([
      'ok' => true,
      'request' => [
        'date_reservation' => $dtDisplay->format('Y-m-d H:i:s'),
        'date_reservation_api' => $dtDisplay->format('Y-m-d H:i:s'),
        'duration' => $duration,
        'spot_id' => $spotId,
        'guests_count' => $guests,
        'hall_id' => $hallId,
      ],
      'free_table_nums' => array_values(array_keys($nums)),
      'occupied_now_nums' => $occupiedNowNums,
      'busy_reasons' => $busyReasons,
      'free_tables' => $filtered,
      'raw' => null,
    ], JSON_UNESCAPED_UNICODE);
  } catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($ajax === 'reservations') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  if ($posterToken === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $dateReservation = trim((string)($_GET['date_reservation'] ?? ''));
  $spotId = (int)($_GET['spot_id'] ?? 1);
  $hallId = 2;
  $displayTz = new DateTimeZone($displayTzName);
  $dtDisplay = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateReservation, $displayTz);
  if ($dtDisplay === false) {
    try { $dtDisplay = new DateTimeImmutable($dateReservation, $displayTz); } catch (\Throwable $e) { $dtDisplay = false; }
  }
  if ($dtDisplay === false || $dateReservation === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректная дата'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($spotId <= 0) $spotId = 1;

  $dayStartDisplay = $dtDisplay->setTime(0, 0, 0);
  $dayEndDisplay = $dtDisplay->setTime(23, 59, 59);

  $api = new \App\Classes\PosterAPI($posterToken);
  try {
    $apiRawOn = (string)($_GET['api_raw'] ?? '') === '1';
    if ($apiRawOn) {
      $params = ['timezone' => 'client'];
      $statusRaw = trim((string)($_GET['status'] ?? ''));
      if ($statusRaw !== '' && preg_match('/^(0|1|7)$/', $statusRaw)) {
        $params['status'] = $statusRaw;
      }
      $dateFromRaw = trim((string)($_GET['date_from'] ?? ''));
      $dateToRaw = trim((string)($_GET['date_to'] ?? ''));
      if ($dateFromRaw !== '') $params['date_from'] = $dateFromRaw;
      if ($dateToRaw !== '') $params['date_to'] = $dateToRaw;
      $resp = $api->request('incomingOrders.getReservations', $params, 'GET');
      echo json_encode([
        'ok' => true,
        'request' => [
          'method' => 'incomingOrders.getReservations',
          'params' => $params,
          'display_timezone' => $displayTzName,
          'api_timezone' => $apiTzName,
        ],
        'count_raw' => is_array($resp) ? count($resp) : 0,
        'poster_response' => $resp,
      ], JSON_UNESCAPED_UNICODE);
      exit;
    }

    $respAll = $api->request('incomingOrders.getReservations', [
      'timezone' => 'client',
    ], 'GET');

    $tablesResp = $api->request('spots.getTableHallTables', [
      'spot_id' => $spotId,
      'hall_id' => $hallId,
      'without_deleted' => 1,
    ], 'GET');

    $tableRows = is_array($tablesResp) ? $tablesResp : [];
    $tableNameById = [];
    foreach ($tableRows as $trRow) {
      if (!is_array($trRow)) continue;
      $id = trim((string)($trRow['table_id'] ?? ''));
      if ($id === '') continue;
      $num = trim((string)($trRow['table_num'] ?? ''));
      $title = trim((string)($trRow['table_title'] ?? ''));
      $scheme = '';
      if (preg_match('/^\d+$/', $title)) $scheme = $title;
      elseif (preg_match('/^\d+$/', $num)) $scheme = $num;
      if ($scheme === '') continue;
      $tableNameById[$id] = (string)$scheme;
    }

    $rows = is_array($respAll) ? $respAll : [];
    $items = [];
    $debugOn = (string)($_GET['debug'] ?? '') === '1';
    $rawOn = (string)($_GET['raw'] ?? '') === '1';
    $targetDay = $dtDisplay->format('Y-m-d');
    $debugRows = [];
    $rawRows = [];
    $includeCanceled = (string)($_GET['include_canceled'] ?? '') === '1';

    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $rowSpot = (int)($row['spot_id'] ?? 0);
      if ($rowSpot !== $spotId) continue;
      $status = (int)($row['status'] ?? 0);
      if (!$includeCanceled && $status === 7) continue;

      $start = trim((string)($row['date_reservation'] ?? ''));
      $dur = (int)($row['duration'] ?? 0);
      $guestsCount = trim((string)($row['guests_count'] ?? ''));
      $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start, $displayTz);
      if ($startDt === false) {
        try { $startDt = new DateTimeImmutable($start, $displayTz); } catch (\Throwable $e) { $startDt = false; }
      }
      if ($startDt === false) continue;
      if ($startDt->format('Y-m-d') !== $targetDay) continue;
      $endDt = $dur > 0 ? $startDt->modify('+' . $dur . ' seconds') : $startDt;
      $guestName = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
      if ($guestName === '') $guestName = '—';

      $incomingOrderId = trim((string)($row['incoming_order_id'] ?? ''));
      $tableId = trim((string)($row['table_id'] ?? ''));
      $tableTitle = $tableId !== '' && isset($tableNameById[$tableId]) ? $tableNameById[$tableId] : '—';

      if ($rawOn) {
        $rawRows[] = [
          'incoming_order_id' => $incomingOrderId,
          'spot_id' => (int)($row['spot_id'] ?? 0),
          'status' => $status,
          'date_reservation' => $start,
          'duration' => $dur,
          'guests_count' => $guestsCount,
          'first_name' => (string)($row['first_name'] ?? ''),
          'last_name' => (string)($row['last_name'] ?? ''),
          'table_id' => $row['table_id'] ?? null,
          'table_title' => $tableTitle,
        ];
      }

      if ($debugOn) {
        $debugRows[] = [
          'incoming_order_id' => $incomingOrderId,
          'status' => $status,
          'date_reservation_raw' => $start,
          'date_start' => $startDt->format('Y-m-d H:i:s'),
          'date_end' => $endDt->format('Y-m-d H:i:s'),
          'guest_name' => $guestName,
          'guests_count' => $guestsCount,
          'table_id' => $tableId,
          'table_title' => $tableTitle,
        ];
      }

      if ($tableTitle === '—') continue;
      $items[] = [
        'table_id' => $tableId !== '' ? $tableId : '—',
        'table_title' => $tableTitle,
        'status' => $status,
        'guest_name' => $guestName,
        'date_start' => $startDt->format('Y-m-d H:i:s'),
        'date_end' => $endDt->format('Y-m-d H:i:s'),
        'guests_count' => $guestsCount,
      ];
    }

    echo json_encode([
      'ok' => true,
      'request' => [
        'date_from' => $dayStartDisplay->format('Y-m-d H:i:s'),
        'date_to' => $dayEndDisplay->format('Y-m-d H:i:s'),
        'date_from_api' => null,
        'date_to_api' => null,
        'spot_id' => $spotId,
        'hall_id' => $hallId,
        'display_timezone' => $displayTzName,
        'api_timezone' => $apiTzName,
        'count_raw' => is_array($respAll) ? count($respAll) : 0,
      ],
      'debug' => $debugOn ? [
        'target_day' => $targetDay,
        'count_target_day' => count($debugRows),
        'rows' => $debugRows,
      ] : null,
      'raw_reservations' => $rawOn ? [
        'target_day' => $targetDay,
        'count_target_day' => count($rawRows),
        'rows' => $rawRows,
      ] : null,
      'reservations_items' => $items,
    ], JSON_UNESCAPED_UNICODE);
  } catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if ($ajax === 'cap_check') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $tableNum = trim((string)($_GET['table_num'] ?? ''));
  $guests = (int)($_GET['guests'] ?? 0);
  if ($tableNum === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный номер стола'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($guests <= 0 || $guests > 99) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректное кол-во гостей'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $cap = isset($tableCapsByNum[$tableNum]) ? (int)$tableCapsByNum[$tableNum] : null;
  if ($cap !== null && $cap > 0 && $guests > $cap) {
    echo json_encode([
      'ok' => true,
      'cap' => $cap,
      'status' => 'warn',
      'message' => tr('cap_warn'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode([
    'ok' => true,
    'cap' => $cap,
    'status' => 'ok',
    'message' => '',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($ajax === 'submit_booking') {
  $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
  $wantsJson = strpos($accept, 'application/json') !== false;
  if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
  } else {
    header('Content-Type: text/html; charset=utf-8');
  }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    if ($wantsJson) echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    else echo '<!doctype html><html lang="ru" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" type="image/svg+xml" href="/links/favicon.svg"><title>Method not allowed</title></head><body><h1>Method not allowed</h1></body></html>';
    exit;
  }
  $payload = [];
  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  if (strpos($ct, 'application/json') !== false) {
    $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
    if (!is_array($payload)) $payload = [];
  } else {
    $payload = is_array($_POST ?? null) ? $_POST : [];
  }

  $respondError = function (int $code, string $msg) use ($wantsJson): void {
    http_response_code($code);
    if ($wantsJson) {
      echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
      $safe = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      echo '<!doctype html><html lang="ru" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" type="image/svg+xml" href="/links/favicon.svg"><title>Ошибка</title></head><body><h1>Ошибка</h1><p>' . $safe . '</p></body></html>';
    }
    exit;
  };
  $respondOk = function (array $data = []) use ($wantsJson): void {
    if ($wantsJson) {
      echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    } else {
      $code = htmlspecialchars((string)($data['qr_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $id = htmlspecialchars((string)($data['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $body = $code !== '' ? ('<p>Код брони: <b>' . $code . '</b></p>') : '';
      if ($id !== '') $body .= '<p>ID: <b>' . $id . '</b></p>';
      echo '<!doctype html><html lang="ru" dir="ltr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="icon" type="image/svg+xml" href="/links/favicon.svg"><title>Заявка отправлена</title></head><body><h1>Заявка отправлена</h1>' . $body . '<p>Мы с вами свяжемся в ближайшее время.</p></body></html>';
    }
    exit;
  };

  $langIn = strtolower(trim((string)($payload['lang'] ?? '')));
  $userLang = in_array($langIn, ['ru', 'en', 'vi'], true) ? $langIn : $lang;
  $trFor = function (string $key) use ($I18N, $userLang): string {
    return isset($I18N[$userLang][$key]) ? (string)$I18N[$userLang][$key] : $key;
  };

  $tableNum = trim((string)($payload['table_num'] ?? ''));
  if ($tableNum === '') $tableNum = trim((string)($payload['table_num_manual'] ?? ''));
  $name = trim((string)($payload['name'] ?? ''));
  $phone = trim((string)($payload['phone'] ?? ''));
  $waPhone = trim((string)($payload['whatsapp_phone'] ?? ''));
  if (!$wantsJson && $waPhone === '') $waPhone = $phone;
  $comment = trim((string)($payload['comment'] ?? ''));
  $preorder = trim((string)($payload['preorder'] ?? ''));
  $preorderRu = trim((string)($payload['preorder_ru'] ?? ''));
  $totalAmount = (int)($payload['total_amount'] ?? 0);
  $guests = (int)($payload['guests'] ?? 0);
  $start = trim((string)($payload['start'] ?? ''));
  $duration_m = (int)($payload['duration_m'] ?? 120);
  if ($start === '') {
    $d = trim((string)($payload['res_date'] ?? ''));
    $t = trim((string)($payload['start_time'] ?? ''));
    if ($d !== '' && $t !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && preg_match('/^\d{2}:\d{2}$/', $t)) {
      $start = $d . 'T' . $t . ':00';
    }
  }

  if ($tableNum === '' || !preg_match('/^\d+$/', $tableNum)) $respondError(400, 'Некорректный номер стола');
  if ($guests <= 0 || $guests > 99) {
    $respondError(400, 'Некорректное кол-во гостей');
  }
  if ($name === '' || mb_strlen($name) > 80) {
    $respondError(400, 'Некорректное имя');
  }
  $phoneNorm = preg_replace('/\D+/', '', (string)$phone);
  $phoneNorm = trim((string)$phoneNorm);
  if ($phoneNorm === '' || !preg_match('/^[1-9]\d{6,15}$/', $phoneNorm)) {
    $respondError(400, $trFor('phone_invalid'));
  }
  $phoneNorm = '+' . $phoneNorm;
  $waDigits = preg_replace('/\D+/', '', (string)$waPhone);
  $waDigits = trim((string)$waDigits);
  $waPhoneNorm = '';
  if ($waDigits !== '' && preg_match('/^[1-9]\d{6,15}$/', $waDigits)) {
    $waPhoneNorm = '+' . $waDigits;
  }
  $comment = str_replace(["\r\n", "\r"], "\n", $comment);
  if (mb_strlen($comment) > 600) $comment = mb_substr($comment, 0, 600);
  $preorder = str_replace(["\r\n", "\r"], "\n", $preorder);
  if (mb_strlen($preorder) > 1200) $preorder = mb_substr($preorder, 0, 1200);
  $preorderRu = str_replace(["\r\n", "\r"], "\n", $preorderRu);
  if (mb_strlen($preorderRu) > 1200) $preorderRu = mb_substr($preorderRu, 0, 1200);
  if ($guests > 5 && trim($preorder) === '') {
    $respondError(400, $trFor('preorder_required'));
  }

  $displayTz = new DateTimeZone($displayTzName);
  $startDt = null;
  try {
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $start)) {
      $startDt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $start, $displayTz) ?: null;
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start)) {
      $startDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start, $displayTz) ?: null;
    } else {
      $startDt = new DateTimeImmutable($start, $displayTz);
    }
  } catch (\Throwable $e) {
    $startDt = null;
  }
  if (!$startDt) {
    $respondError(400, 'Некорректное время');
  }

  // Enforce latest booking limits
  $metaRepo = new \App\Classes\MetaRepository($db);
  $workdayKey = 'reservations_latest_workday';
  $weekendKey = 'reservations_latest_weekend';
  $vals = $metaRepo->getMany([$workdayKey, $weekendKey]);
  $latestWorkday = array_key_exists($workdayKey, $vals) ? trim((string)$vals[$workdayKey]) : '21:00';
  $latestWeekend = array_key_exists($weekendKey, $vals) ? trim((string)$vals[$weekendKey]) : '22:00';
  if ($latestWorkday === '') $latestWorkday = '21:00';
  if ($latestWeekend === '') $latestWeekend = '22:00';

  $reqDay = (int)$startDt->format('N'); // 1 (Mon) to 7 (Sun)
  $limitStr = ($reqDay >= 1 && $reqDay <= 4) ? $latestWorkday : $latestWeekend;
  $limitParts = explode(':', $limitStr);
  $limitH = (int)($limitParts[0] ?? 21);
  $limitM = (int)($limitParts[1] ?? 0);
  
  $reqH = (int)$startDt->format('H');
  $reqM = (int)$startDt->format('i');
  
  if ($reqH > $limitH || ($reqH === $limitH && $reqM > $limitM)) {
      $respondError(400, 'Извините, мы скоро закрываемся, забронировать столик на это время уже нельзя.');
  }

  $tg = is_array($payload['tg'] ?? null) ? $payload['tg'] : [];
  $tgUid = isset($tg['user_id']) ? (int)$tg['user_id'] : 0;
  $tgUn = strtolower(trim((string)($tg['username'] ?? '')));
  $tgUn = ltrim($tgUn, '@');
  if ($waPhoneNorm !== '') {
    $tgUid = 0;
    $tgUn = '';
  }

  if (!isset($db) || !($db instanceof \App\Classes\Database)) {
    $respondError(500, 'DB не настроена');
  }

  $db->createReservationsTable();
  $resTable = $db->t('reservations');
  $qrUrl = '';
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $qrCode = '';
  for ($i = 0; $i < 8; $i++) {
    $qrCode .= $alphabet[random_int(0, strlen($alphabet) - 1)];
  }
  if ($totalAmount > 0) {
    $qrUrl = "https://qr.sepay.vn/img?acc=96247Y294A&bank=BIDV&amount={$totalAmount}&des=" . urlencode("RES{$qrCode}");
  }

  $db->query("INSERT INTO {$resTable} (
    created_at, start_time, duration, guests, table_num, name, phone, whatsapp_phone, comment, preorder_text, preorder_ru, tg_user_id, tg_username, lang, total_amount, qr_url, qr_code
  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
    (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
    $startDt->format('Y-m-d H:i:s'),
    $duration_m,
    $guests,
    $tableNum,
    $name,
    $phoneNorm,
    $waPhoneNorm !== '' ? $waPhoneNorm : null,
    $comment,
    $preorder,
    $preorderRu,
    $tgUid > 0 ? $tgUid : null,
    $tgUn !== '' ? $tgUn : null,
    $userLang,
    $totalAmount,
    $qrUrl,
    $qrCode
  ]);
  $resId = $db->getPdo()->lastInsertId();

  $payload = [
    'qr_code' => (string)$qrCode,
    'start_time' => $startDt->format('Y-m-d H:i:s'),
    'duration' => $duration_m,
    'guests' => $guests,
    'table_num' => $tableNum,
    'name' => $name,
    'phone' => $phoneNorm,
    'whatsapp_phone' => $waPhoneNorm !== '' ? $waPhoneNorm : '',
    'comment' => $comment,
    'preorder_text' => $preorder,
    'preorder_ru' => $preorderRu,
    'tg_user_id' => $tgUid,
    'tg_username' => $tgUn,
  ];
  $keyboard = \App\Classes\ReservationTelegram::keyboardActive((int)$resId);
  try {
    $msgId = reservations_send_manager_booking($db, $resTable, (int)$resId, $payload, $keyboard);
  } catch (\Throwable $e) {
    $msgId = 0;
  }
  if ($msgId <= 0) {
    $respondError(500, 'Не удалось отправить сообщение в Telegram');
  }

  if ($waPhoneNorm === '' && $tgUid <= 0) {
    $respondError(400, 'Мессенджер не привязан');
  }

  if ($waPhoneNorm !== '') {
    $waSecret = trim((string)($_ENV['WA_NODE_SECRET'] ?? ($_ENV['WA_BRIDGE_SECRET'] ?? '')));
    if ($waSecret === '') {
      $respondError(500, $trFor('err_wa_not_configured'));
    }
    $waText = $trFor('tg_thanks_title') . ' ' . $trFor('tg_thanks_body') . "\n\n";
    if ($qrUrl !== '') {
      $waText .= ($trFor('qr_payment_title') ?: 'Оплата предзаказа') . "\n";
      $waText .= ($trFor('qr_payment_body') ?: '') . "\n";
      $waText .= $qrUrl . "\n\n";
    }
    $waText .= $trFor('tg_booking_title') . ' #' . $qrCode . "\n";
    $waText .= $trFor('tg_date') . ': ' . $startDt->format('Y-m-d') . "\n";
    $waText .= $trFor('tg_time') . ': ' . $startDt->format('H:i') . "\n";
    $waText .= $trFor('tg_guests') . ': ' . $guests . "\n";
    $waText .= $trFor('tg_table') . ': ' . $tableNum . "\n";
    $waText .= $trFor('tg_name') . ': ' . $name . "\n";
    $waText .= $trFor('tg_phone') . ': ' . $phoneNorm;
    if ($comment !== '') $waText .= "\n\n" . $trFor('tg_comment') . ":\n" . $comment;
    if ($preorder !== '') $waText .= "\n\n" . $trFor('tg_preorder') . ":\n" . $preorder;

    $sent = wa_bridge_send($waPhoneNorm, $waText);
    if (!$sent) {
      $respondError(500, $trFor('err_wa_send_failed'));
    }
    $respondOk(['id' => $resId, 'qr_code' => $qrCode]);
  }

  $userText = '<b>' . htmlspecialchars($trFor('tg_thanks_title')) . '</b> ' . htmlspecialchars($trFor('tg_thanks_body')) . "\n\n";
  if ($qrUrl !== '') {
    $userText .= '<b>' . htmlspecialchars($trFor('qr_payment_title') ?? 'Оплата предзаказа') . '</b>' . "\n";
    $userText .= htmlspecialchars($trFor('qr_payment_body') ?? 'Пожалуйста, отсканируйте QR-код для оплаты предзаказа. В назначении платежа уже указан номер вашей брони.') . "\n\n";
    $userText .= '<a href="' . htmlspecialchars($qrUrl) . '">Ссылка на QR-код для оплаты</a>' . "\n\n";
  }
  $userText .= '<b>' . htmlspecialchars($trFor('tg_booking_title')) . ' #' . htmlspecialchars((string)$qrCode) . '</b>' . "\n";
  $userText .= htmlspecialchars($trFor('tg_date')) . ': <b>' . htmlspecialchars($startDt->format('Y-m-d')) . '</b>' . "\n";
  $userText .= htmlspecialchars($trFor('tg_time')) . ': <b>' . htmlspecialchars($startDt->format('H:i')) . '</b>' . "\n";
  $userText .= htmlspecialchars($trFor('tg_guests')) . ': <b>' . htmlspecialchars((string)$guests) . '</b>' . "\n";
  $userText .= htmlspecialchars($trFor('tg_table')) . ': <b>' . htmlspecialchars($tableNum) . '</b>' . "\n";
  $userText .= htmlspecialchars($trFor('tg_name')) . ': <b>' . htmlspecialchars($name) . '</b>' . "\n";
  $userText .= htmlspecialchars($trFor('tg_phone')) . ': <b>' . htmlspecialchars($phoneNorm) . '</b>';
  if ($comment !== '') {
    $userText .= "\n";
    $userText .= '<b>' . htmlspecialchars($trFor('tg_comment')) . ':</b>' . "\n" . htmlspecialchars($comment);
  }
  if ($preorder !== '') {
    $userText .= "\n";
    $userText .= '<b>' . htmlspecialchars($trFor('tg_preorder')) . ':</b>' . "\n" . htmlspecialchars($preorder);
  }
  $userText .= "\n\n" . htmlspecialchars($trFor('booking_note'));

  $ch = curl_init();
  if ($qrUrl !== '') {
    $chImg = curl_init($qrUrl);
    curl_setopt($chImg, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chImg, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($chImg, CURLOPT_FOLLOWLOCATION, true);
    $imgData = curl_exec($chImg);
    curl_close($chImg);

    $tmpFile = tempnam(sys_get_temp_dir(), 'qr_');
    file_put_contents($tmpFile, $imgData);

    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$tgToken}/sendPhoto");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
      'chat_id' => (string)$tgUid,
      'photo' => new CURLFile($tmpFile, 'image/png', 'qr.png'),
      'caption' => $userText,
      'parse_mode' => 'HTML',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $resp = curl_exec($ch);
    curl_close($ch);
    @unlink($tmpFile);
  } else {
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$tgToken}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
      'chat_id' => (string)$tgUid,
      'text' => $userText,
      'parse_mode' => 'HTML',
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
  }
  $data = $resp ? json_decode($resp, true) : null;

  if ($qrUrl !== '' && (!is_array($data) || empty($data['ok']))) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot{$tgToken}/sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
      'chat_id' => (string)$tgUid,
      'text' => $userText,
      'parse_mode' => 'HTML',
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = $resp ? json_decode($resp, true) : null;
  }

  if (!is_array($data) || empty($data['ok'])) {
    $respondError(500, 'Не удалось отправить сообщение гостю в Telegram');
  }
  $respondOk(['id' => $resId, 'qr_code' => $qrCode]);
}

if ($ajax === 'tg_state_create') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (!isset($db) || !($db instanceof \App\Classes\Database)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB не настроена'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $raw = file_get_contents('php://input');
  if ($raw === false) $raw = '';
  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  $looksJson = preg_match('/^\s*[\{\[]/', (string)$raw) === 1;
  if (strpos($ct, 'application/json') !== false || $looksJson) {
    $payload = json_decode($raw !== '' ? $raw : '[]', true);
    if (!is_array($payload)) $payload = [];
  } else {
    $payload = is_array($_POST ?? null) ? $_POST : [];
  }

  $tableNum = trim((string)($payload['table_num'] ?? ''));
  $guests = (int)($payload['guests'] ?? 0);
  $start = trim((string)($payload['start'] ?? ''));
  if ($tableNum === '' || !preg_match('/^\d+$/', $tableNum)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный номер стола'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($guests <= 0 || $guests > 99) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректное кол-во гостей'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $scriptBase = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api.php'));
  $sourcePage = trim((string)($payload['source_page'] ?? $scriptBase));

  if ($start === '' || mb_strlen($start) > 40) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректное время'], JSON_UNESCAPED_UNICODE);
    exit;
  }

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
      } catch (\Throwable $e) {
      }
    }
  }
  if ($tgUserBot === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не задан username бота Telegram'], JSON_UNESCAPED_UNICODE);
    exit;
  }

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
  try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_user_id BIGINT NULL"); } catch (\Throwable $e) {}
  try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_username VARCHAR(64) NULL"); } catch (\Throwable $e) {}
  try { $pdo->exec("ALTER TABLE {$t} ADD COLUMN tg_name VARCHAR(128) NULL"); } catch (\Throwable $e) {}

  $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($payloadJson === false) $payloadJson = '{}';

  $db->query("INSERT INTO {$t} (code, payload_json, created_at, expires_at) VALUES (?, ?, ?, ?)", [$code, $payloadJson, $createdAt, $expiresAt]);

  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $returnUrl = ($host !== '' ? ($scheme . '://' . $host) : '') . '/' . ltrim($sourcePage, '/') . '?tg_state=' . rawurlencode($code);
  $botUrl = 'https://t.me/' . rawurlencode($tgUserBot) . '?start=' . rawurlencode($code);

  echo json_encode(['ok' => true, 'code' => $code, 'bot_url' => $botUrl, 'return_url' => $returnUrl], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($ajax === 'wa_state_create') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if (!isset($db) || !($db instanceof \App\Classes\Database)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB не настроена'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $waSecret = trim((string)($_ENV['WA_NODE_SECRET'] ?? ($_ENV['WA_BRIDGE_SECRET'] ?? '')));
  if ($waSecret === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => tr('err_wa_not_configured')], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $raw = file_get_contents('php://input');
  if ($raw === false) $raw = '';
  $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
  $looksJson = preg_match('/^\s*[\{\[]/', (string)$raw) === 1;
  if (strpos($ct, 'application/json') !== false || $looksJson) {
    $payload = json_decode($raw !== '' ? $raw : '[]', true);
    if (!is_array($payload)) $payload = [];
  } else {
    $payload = is_array($_POST ?? null) ? $_POST : [];
  }

  $tableNum = trim((string)($payload['table_num'] ?? ''));
  $guests = (int)($payload['guests'] ?? 0);
  $start = trim((string)($payload['start'] ?? ''));
  $phone = trim((string)($payload['phone'] ?? ''));
  $scriptBase = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'api.php'));
  $sourcePage = trim((string)($payload['source_page'] ?? $scriptBase));

  if ($tableNum === '' || !preg_match('/^\d+$/', $tableNum)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный номер стола'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($guests <= 0 || $guests > 99) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректное кол-во гостей'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($start === '' || mb_strlen($start) > 40) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректное время'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $waDigits = preg_replace('/\D+/', '', $phone);
  $waDigits = trim((string)$waDigits);
  if ($waDigits === '' || !preg_match('/^[1-9]\d{6,15}$/', $waDigits)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => tr('phone_invalid')], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $phoneNorm = '+' . $waDigits;

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
  if (!$sent) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => tr('err_wa_send_failed')], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode(['ok' => true, 'code' => $code, 'return_url' => $returnUrl], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($ajax === 'tg_state_get') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  if (!isset($db) || !($db instanceof \App\Classes\Database)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB не настроена'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $code = trim((string)($_GET['code'] ?? ''));
  if ($code === '' || !preg_match('/^[a-f0-9]{8,40}$/', $code)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $t = $db->t('table_reservation_tg_states');
  try {
    $row = $db->query("SELECT payload_json, expires_at, used_at, tg_user_id, tg_username, tg_name FROM {$t} WHERE code = ? LIMIT 1", [$code])->fetch();
  } catch (\Throwable $e) {
    $row = false;
  }
  if (!$row || !is_array($row)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $usedAt = (string)($row['used_at'] ?? '');
  if ($usedAt !== '') {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'Expired'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $expiresAt = (string)($row['expires_at'] ?? '');
  $expTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
  if ($expTs === false || $expTs < time()) {
    $db->query("DELETE FROM {$t} WHERE code = ?", [$code]);
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'Expired'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $db->query("UPDATE {$t} SET used_at = ? WHERE code = ?", [(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $code]);
  $payloadJson = (string)($row['payload_json'] ?? '{}');
  $payload = json_decode($payloadJson, true);
  if (!is_array($payload)) $payload = [];
  $tgUserId = (int)($row['tg_user_id'] ?? 0);
  $tgUsername = trim((string)($row['tg_username'] ?? ''));
  $tgName = trim((string)($row['tg_name'] ?? ''));
  echo json_encode(['ok' => true, 'payload' => $payload, 'tg' => ['user_id' => $tgUserId, 'username' => $tgUsername, 'name' => $tgName]], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($ajax === 'wa_state_get') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  if (!isset($db) || !($db instanceof \App\Classes\Database)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB не настроена'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $code = trim((string)($_GET['code'] ?? ''));
  if ($code === '' || !preg_match('/^[a-f0-9]{8,40}$/', $code)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $t = $db->t('table_reservation_wa_states');
  try {
    $row = $db->query("SELECT payload_json, phone, expires_at, used_at FROM {$t} WHERE code = ? LIMIT 1", [$code])->fetch();
  } catch (\Throwable $e) {
    $row = false;
  }
  if (!$row || !is_array($row)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $usedAt = (string)($row['used_at'] ?? '');
  if ($usedAt !== '') {
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'Expired'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $expiresAt = (string)($row['expires_at'] ?? '');
  $expTs = $expiresAt !== '' ? strtotime($expiresAt) : false;
  if ($expTs === false || $expTs < time()) {
    $db->query("DELETE FROM {$t} WHERE code = ?", [$code]);
    http_response_code(410);
    echo json_encode(['ok' => false, 'error' => 'Expired'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $db->query("UPDATE {$t} SET used_at = ? WHERE code = ?", [(new DateTimeImmutable('now'))->format('Y-m-d H:i:s'), $code]);
  $payloadJson = (string)($row['payload_json'] ?? '{}');
  $payload = json_decode($payloadJson, true);
  if (!is_array($payload)) $payload = [];
  $phone = trim((string)($row['phone'] ?? ''));
  echo json_encode(['ok' => true, 'payload' => $payload, 'phone' => $phone], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($ajax === 'menu_preorder') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  if (!isset($db) || !($db instanceof \App\Classes\Database)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB not configured'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $supportedMenuLangs = ['ru', 'en', 'vi', 'ko'];
  $menuLang = strtolower(trim((string)($_GET['lang'] ?? 'ru')));
  if (!in_array($menuLang, $supportedMenuLangs, true)) $menuLang = 'ru';
  $trLang = $menuLang === 'vi' ? 'vn' : $menuLang;

  try {
    $db->createMenuTables();
  } catch (\Throwable $e) {
  }

  $metaTable = $db->t('system_meta');
  $pmi = $db->t('poster_menu_items');
  $mw = $db->t('menu_workshops');
  $mwTr = $db->t('menu_workshop_tr');
  $mc = $db->t('menu_categories');
  $mcTr = $db->t('menu_category_tr');
  $mi = $db->t('menu_items');
  $miTr = $db->t('menu_item_tr');

  $lastMenuSyncAt = null;
  try {
    $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = 'menu_last_sync_at' LIMIT 1")->fetch();
    if (is_array($row) && !empty($row['meta_value'])) $lastMenuSyncAt = (string)$row['meta_value'];
  } catch (\Throwable $e) {
  }

  try {
    $rows = $db->query(
      "SELECT
          w.id AS workshop_id,
          COALESCE(NULLIF(wtr.name,''), NULLIF(w.name_raw,''), '') AS main_label,
          c.id AS category_id,
          COALESCE(NULLIF(ctr.name,''), NULLIF(c.name_raw,''), '') AS sub_label,
          mi.id AS menu_item_id,
          p.poster_id,
          p.price_raw,
          COALESCE(NULLIF(itr.title,''), NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS title,
          COALESCE(NULLIF(itr_ru.title,''), NULLIF(p.name_raw,''), '') AS ru_title,
          COALESCE(NULLIF(itr.description,''), NULLIF(itr_ru.description,''), '') AS description,
          COALESCE(NULLIF(mi.image_url,''), '') AS image_url,
          COALESCE(mi.sort_order, 0) AS sort_order,
          COALESCE(w.sort_order, 0) AS main_sort,
          COALESCE(c.sort_order, 0) AS sub_sort
       FROM {$mi} mi
       JOIN {$pmi} p ON p.id = mi.poster_item_id AND p.is_active = 1
       JOIN {$mc} c ON c.id = mi.category_id AND c.show_on_site = 1
       JOIN {$mw} w ON w.id = c.workshop_id AND w.show_on_site = 1
       LEFT JOIN {$miTr} itr ON itr.item_id = mi.id AND itr.lang = ?
       LEFT JOIN {$miTr} itr_ru ON itr_ru.item_id = mi.id AND itr_ru.lang = 'ru'
       LEFT JOIN {$mcTr} ctr ON ctr.category_id = c.id AND ctr.lang = ?
       LEFT JOIN {$mwTr} wtr ON wtr.workshop_id = w.id AND wtr.lang = ?
       WHERE mi.is_published = 1
       ORDER BY
          w.sort_order ASC,
          main_label ASC,
          c.sort_order ASC,
          sub_label ASC,
          mi.sort_order ASC,
          title ASC",
      [$trLang, $trLang, $trLang]
    )->fetchAll();
  } catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Menu query failed'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $groups = [];
  foreach ($rows as $it) {
    if (!is_array($it)) continue;
    $mainLabel = trim((string)($it['main_label'] ?? ''));
    $subLabel = trim((string)($it['sub_label'] ?? ''));
    if ($mainLabel === '' || $subLabel === '') continue;
    $workshopId = (int)($it['workshop_id'] ?? 0);
    $categoryId = (int)($it['category_id'] ?? 0);
    $mainSort = (int)($it['main_sort'] ?? 0);
    $subSort = (int)($it['sub_sort'] ?? 0);
    $sortOrder = (int)($it['sort_order'] ?? 0);

    $groupsKey = $workshopId . '|' . $mainLabel;
    if (!isset($groups[$groupsKey])) {
      $groups[$groupsKey] = ['workshop_id' => $workshopId, 'title' => $mainLabel, 'sort' => $mainSort, 'categories' => []];
    }

    $catKey = $categoryId . '|' . $subLabel;
    if (!isset($groups[$groupsKey]['categories'][$catKey])) {
      $groups[$groupsKey]['categories'][$catKey] = ['category_id' => $categoryId, 'title' => $subLabel, 'sort' => $subSort, 'items' => []];
    }

    $title = trim((string)($it['title'] ?? ''));
    if ($title === '') continue;
    $priceRaw = (string)($it['price_raw'] ?? '');
    $price = is_numeric($priceRaw) ? (int)$priceRaw : null;

    $groups[$groupsKey]['categories'][$catKey]['items'][] = [
      'id' => (int)($it['menu_item_id'] ?? 0),
      'title' => $title,
      'ru_title' => trim((string)($it['ru_title'] ?? '')),
      'price' => $price,
      'description' => trim((string)($it['description'] ?? '')),
      'image_url' => trim((string)($it['image_url'] ?? '')),
      'sort' => $sortOrder,
    ];
  }

  $out = array_values($groups);
  usort($out, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
  foreach ($out as &$g) {
    $cats = isset($g['categories']) && is_array($g['categories']) ? array_values($g['categories']) : [];
    usort($cats, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
    foreach ($cats as &$c) {
      $items = isset($c['items']) && is_array($c['items']) ? $c['items'] : [];
      usort($items, fn($a, $b) => ((int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0)) ?: strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? '')));
      $c['items'] = $items;
    }
    unset($c);
    $g['categories'] = $cats;
  }
  unset($g);

  echo json_encode(['ok' => true, 'lang' => $menuLang, 'last_sync_at' => $lastMenuSyncAt, 'groups' => $out], JSON_UNESCAPED_UNICODE);
  exit;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
exit;
