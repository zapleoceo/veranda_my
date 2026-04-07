<?php

if (file_exists(__DIR__ . '/.env')) {
  $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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
  $apiTzName = 'Europe/Kyiv';
}
date_default_timezone_set($apiTzName);

require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/TelegramBot.php';

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
$now = new DateTimeImmutable('now', new DateTimeZone($displayTzName));
$roundedNow = $now->setTime((int)$now->format('H'), (int)$now->format('i'), 0);
$m = (int)$roundedNow->format('i');
$add = (15 - ($m % 15)) % 15;
if ($add > 0) $roundedNow = $roundedNow->modify('+' . $add . ' minutes');
$defaultResDateLocal = $roundedNow->format('Y-m-d\TH:i');
$hallIdForSettings = 2;
$allowedSchemeNums = null;
$tableCapsByNum = [
  '1' => 8, '2' => 8, '3' => 8,
  '4' => 5, '5' => 5, '6' => 5,
  '7' => 8,
  '8' => 2, '9' => 2, '10' => 2, '11' => 2,
  '12' => 3, '13' => 3, '14' => 3,
  '15' => 5, '16' => 5, '17' => 5, '18' => 5, '19' => 5,
  '20' => 15,
];
try {
  $dbHost = trim((string)($_ENV['DB_HOST'] ?? ''));
  $dbName = trim((string)($_ENV['DB_NAME'] ?? ''));
  $dbUser = trim((string)($_ENV['DB_USER'] ?? ''));
  $dbPass = (string)($_ENV['DB_PASS'] ?? '');
  $dbSuffix = trim((string)($_ENV['DB_TABLE_SUFFIX'] ?? ''));

  if ($dbHost !== '' && $dbName !== '' && $dbUser !== '') {
    require_once __DIR__ . '/src/classes/Database.php';
    require_once __DIR__ . '/src/classes/MetaRepository.php';
    $db = new \App\Classes\Database($dbHost, $dbName, $dbUser, $dbPass, $dbSuffix);
    $metaRepo = new \App\Classes\MetaRepository($db);
    $key = 'reservations_allowed_scheme_nums_hall_' . $hallIdForSettings;
    $capsKey = 'reservations_table_caps_hall_' . $hallIdForSettings;
    $vals = $metaRepo->getMany([$key]);
    $stored = array_key_exists($key, $vals) ? trim((string)$vals[$key]) : '';
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

if (($_GET['ajax'] ?? '') === 'free_tables') {
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

  $dateReservation = trim($dateReservation);
  $displayTz = new DateTimeZone($displayTzName);
  $apiTz = new DateTimeZone($apiTzName);
  $dtDisplay = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateReservation, $displayTz);
  if ($dtDisplay === false) {
    try { $dtDisplay = new DateTimeImmutable($dateReservation, $displayTz); } catch (\Throwable $e) { $dtDisplay = false; }
  }
  if ($dtDisplay === false || $dateReservation === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректная дата'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $dtApi = $dtDisplay->setTimezone($apiTz);
  if ($duration < 1800) $duration = 7200;
  if ($guests <= 0) $guests = 2;
  if ($spotId <= 0) $spotId = 1;

  $api = new \App\Classes\PosterAPI($posterToken);
  try {
    $resp = $api->request('incomingOrders.getTablesForReservation', [
      'date_reservation' => $dtApi->format('Y-m-d H:i:s'),
      'duration' => $duration,
      'spot_id' => $spotId,
      'guests_count' => $guests,
    ], 'GET');

    $free = is_array($resp) && isset($resp['freeTables']) && is_array($resp['freeTables']) ? $resp['freeTables'] : [];
    $filtered = [];
    $nums = [];
    $allowedSet = is_array($allowed) ? array_fill_keys(array_map('strval', $allowed), true) : null;
    foreach ($free as $row) {
      if (!is_array($row)) continue;
      if ((int)($row['hall_id'] ?? 0) !== $hallId) continue;
      $num = trim((string)($row['table_num'] ?? ''));
      if ($num === '') continue;
      if (is_array($allowedSet) && !isset($allowedSet[$num])) continue;
      $filtered[] = $row;
      $nums[$num] = true;
    }

    echo json_encode([
      'ok' => true,
      'request' => [
        'date_reservation' => $dtDisplay->format('Y-m-d H:i:s'),
        'date_reservation_api' => $dtApi->format('Y-m-d H:i:s'),
        'duration' => $duration,
        'spot_id' => $spotId,
        'guests_count' => $guests,
        'hall_id' => $hallId,
      ],
      'free_table_nums' => array_values(array_keys($nums)),
      'free_tables' => $filtered,
      'raw' => $resp,
    ], JSON_UNESCAPED_UNICODE);
  } catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if (($_GET['ajax'] ?? '') === 'reservations') {
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
  $spotId = (int)($_GET['spot_id'] ?? 1);
  $hallId = 2;
  $allowed = $allowedSchemeNums;

  $displayTz = new DateTimeZone($displayTzName);
  $apiTz = new DateTimeZone($apiTzName);
  $dateReservation = trim($dateReservation);
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
  $dayStartApi = $dayStartDisplay->setTimezone($apiTz);
  $dayEndApi = $dayEndDisplay->setTimezone($apiTz);

  $api = new \App\Classes\PosterAPI($posterToken);
  try {
    $resp = $api->request('incomingOrders.getReservations', [
      'date_from' => $dayStartApi->format('Y-m-d H:i:s'),
      'date_to' => $dayEndApi->format('Y-m-d H:i:s'),
    ], 'GET');

    $tablesResp = $api->request('spots.getTableHallTables', [
      'spot_id' => $spotId,
      'hall_id' => $hallId,
      'without_deleted' => 1,
    ], 'GET');

    $tableRows = is_array($tablesResp) ? $tablesResp : [];
    $tableNameById = [];
    $allowedSet = is_array($allowed) ? array_fill_keys(array_map('strval', $allowed), true) : null;
    foreach ($tableRows as $tr) {
      if (!is_array($tr)) continue;
      $id = trim((string)($tr['table_id'] ?? ''));
      if ($id === '') continue;
      $num = trim((string)($tr['table_num'] ?? ''));
      $title = trim((string)($tr['table_title'] ?? ''));
      $scheme = '';
      if (preg_match('/^\d+$/', $num)) $scheme = $num;
      elseif (preg_match('/^\d+$/', $title)) $scheme = $title;
      if ($scheme === '') continue;
      $sInt = (int)$scheme;
      if ($sInt < 1 || $sInt > 20) continue;
      if (is_array($allowedSet) && !isset($allowedSet[(string)$sInt])) continue;
      $tableNameById[$id] = (string)$sInt;
    }

    $rows = is_array($resp) ? $resp : [];
    $items = [];

    $extractNums = function ($value) use (&$extractNums) {
      $out = [];
      if (is_int($value) || is_float($value)) {
        $s = (string)$value;
        if ($s !== '') $out[] = $s;
        return $out;
      }
      if (is_string($value)) {
        if (preg_match_all('/\b\d+\b/u', $value, $m)) {
          foreach ($m[0] as $n) $out[] = (string)$n;
        }
        return $out;
      }
      if (is_array($value)) {
        foreach ($value as $v) {
          foreach ($extractNums($v) as $n) $out[] = $n;
        }
        return $out;
      }
      return $out;
    };

    $detailCache = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $status = (int)($row['status'] ?? 0);

      $start = trim((string)($row['date_reservation'] ?? ''));
      $dur = (int)($row['duration'] ?? 0);
      $guestsCount = trim((string)($row['guests_count'] ?? ''));
      $startDtApi = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $start, $apiTz);
      if ($startDtApi === false) {
        try { $startDtApi = new DateTimeImmutable($start, $apiTz); } catch (\Throwable $e) { $startDtApi = false; }
      }
      if ($startDtApi === false) continue;
      $startDt = $startDtApi->setTimezone($displayTz);
      $endDt = $dur > 0 ? $startDt->modify('+' . $dur . ' seconds') : $startDt;
      $guestName = trim(((string)($row['first_name'] ?? '')) . ' ' . ((string)($row['last_name'] ?? '')));
      if ($guestName === '') $guestName = '—';

      $tableCandidates = [];
      foreach (['table_id', 'table_ids', 'tables', 'table'] as $k) {
        if (!array_key_exists($k, $row)) continue;
        foreach ($extractNums($row[$k]) as $n) $tableCandidates[] = $n;
      }

      $incomingOrderId = trim((string)($row['incoming_order_id'] ?? ''));
      if ($incomingOrderId !== '' && count($tableCandidates) === 0) {
        if (!array_key_exists($incomingOrderId, $detailCache)) {
          try {
            $detailCache[$incomingOrderId] = $api->request('incomingOrders.getReservation', [
              'incoming_order_id' => $incomingOrderId,
            ], 'GET');
          } catch (\Throwable $e) {
            $detailCache[$incomingOrderId] = null;
          }
        }
        $detail = $detailCache[$incomingOrderId];
        if (is_array($detail)) {
          foreach (['table_id', 'table_ids', 'tables', 'table'] as $k) {
            if (!array_key_exists($k, $detail)) continue;
            foreach ($extractNums($detail[$k]) as $n) $tableCandidates[] = $n;
          }
        }
      }

      $tableIds = [];
      foreach ($tableCandidates as $n) {
        $id = (string)$n;
        if ($id === '' || isset($tableIds[$id])) continue;
        $tableIds[$id] = true;
      }

      if (!$tableIds) {
        $items[] = [
          'table_id' => '—',
          'table_title' => '—',
          'status' => $status,
          'guest_name' => $guestName,
          'date_start' => $startDt->format('Y-m-d H:i:s'),
          'date_end' => $endDt->format('Y-m-d H:i:s'),
          'guests_count' => $guestsCount,
        ];
        continue;
      }

      foreach (array_keys($tableIds) as $tableId) {
        if (!isset($tableNameById[$tableId])) continue;
        $items[] = [
          'table_id' => $tableId,
          'table_title' => $tableNameById[$tableId],
          'status' => $status,
          'guest_name' => $guestName,
          'date_start' => $startDt->format('Y-m-d H:i:s'),
          'date_end' => $endDt->format('Y-m-d H:i:s'),
          'guests_count' => $guestsCount,
        ];
      }
    }

    echo json_encode([
      'ok' => true,
      'request' => [
        'date_from' => $dayStartDisplay->format('Y-m-d H:i:s'),
        'date_to' => $dayEndDisplay->format('Y-m-d H:i:s'),
        'date_from_api' => $dayStartApi->format('Y-m-d H:i:s'),
        'date_to_api' => $dayEndApi->format('Y-m-d H:i:s'),
        'spot_id' => $spotId,
        'hall_id' => $hallId,
        'display_timezone' => $displayTzName,
        'api_timezone' => $apiTzName,
        'count_raw' => is_array($resp) ? count($resp) : 0,
      ],
      'reservations_items' => $items,
    ], JSON_UNESCAPED_UNICODE);
  } catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if (($_GET['ajax'] ?? '') === 'busy_ranges') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  if ($posterToken === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'POSTER_API_TOKEN не задан'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $spotId = (int)($_GET['spot_id'] ?? 1);
  $hallId = 2;
  $date = trim((string)($_GET['date'] ?? ''));
  if ($spotId <= 0) $spotId = 1;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректная дата'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $allowed = $allowedSchemeNums;
  $allowedNums = [];
  if (is_array($allowed) && count($allowed) > 0) {
    foreach ($allowed as $v) {
      $n = (int)$v;
      if ($n >= 1 && $n <= 500) $allowedNums[(string)$n] = true;
    }
  } else {
    for ($i = 1; $i <= 20; $i++) $allowedNums[(string)$i] = true;
  }

  $allowedList = array_values(array_keys($allowedNums));
  usort($allowedList, fn($a, $b) => (int)$a <=> (int)$b);

  $displayTz = new DateTimeZone($displayTzName);
  $apiTz = new DateTimeZone($apiTzName);
  $tzName = $displayTzName;

  $api = new \App\Classes\PosterAPI($posterToken);
  $errors = [];

  try {
    $slotStep = 900;
    $duration = 1800;
    $guests = 1;

    $startMin = 9 * 60;
    $endMin = 23 * 60;
    $slots = [];
    for ($m = $startMin; $m < $endMin; $m += 15) {
      $hh = str_pad((string)floor($m / 60), 2, '0', STR_PAD_LEFT);
      $mm = str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT);
      $slots[] = $date . ' ' . $hh . ':' . $mm . ':00';
    }

    $busyByNum = [];
    $slotStarts = [];
    foreach ($allowedList as $n) $busyByNum[$n] = [];

    foreach ($slots as $idx => $slotStart) {
      try {
        $slotDisplayDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $slotStart, $displayTz);
        if ($slotDisplayDt === false) continue;
        $slotApiDt = $slotDisplayDt->setTimezone($apiTz);
        $resp = $api->request('incomingOrders.getTablesForReservation', [
          'date_reservation' => $slotApiDt->format('Y-m-d H:i:s'),
          'duration' => $duration,
          'spot_id' => $spotId,
          'guests_count' => $guests,
        ], 'GET');

        $free = is_array($resp) && isset($resp['freeTables']) && is_array($resp['freeTables']) ? $resp['freeTables'] : [];
        $freeSet = [];
        foreach ($free as $row) {
          if (!is_array($row)) continue;
          if ((int)($row['hall_id'] ?? 0) !== $hallId) continue;
          $num = trim((string)($row['table_num'] ?? ''));
          if ($num === '') continue;
          $freeSet[$num] = true;
        }

        $slotStarts[$idx] = $slotStart;
        foreach ($allowedList as $n) {
          if (!isset($freeSet[$n])) $busyByNum[$n][] = $idx;
        }
      } catch (\Throwable $e) {
        $errors[] = ['slot' => $slotStart, 'error' => $e->getMessage()];
      }
    }

    $rangesServer = [];
    $rangesTs = [];
    foreach ($allowedList as $n) {
      $ids = $busyByNum[$n];
      sort($ids);
      $out = [];
      $runStart = null;
      $prev = null;
      foreach ($ids as $i) {
        if ($runStart === null) { $runStart = $i; $prev = $i; continue; }
        if ($i === $prev + 1) { $prev = $i; continue; }
        $out[] = [$runStart, $prev];
        $runStart = $i;
        $prev = $i;
      }
      if ($runStart !== null) $out[] = [$runStart, $prev];

      $txt = [];
      $tsOut = [];
      foreach ($out as [$a, $b]) {
        if (!isset($slotStarts[$a]) || !isset($slotStarts[$b])) continue;
        $aDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $slotStarts[$a], $displayTz);
        $bDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $slotStarts[$b], $displayTz);
        if ($aDt === false || $bDt === false) continue;
        $aTs = $aDt->getTimestamp();
        $bTs = $bDt->getTimestamp();
        $startStr = $aDt->format('H:i');
        $endStr = (new DateTimeImmutable('@' . ($bTs + $slotStep)))->setTimezone($displayTz)->format('H:i');
        $txt[] = $startStr . '-' . $endStr;
        $tsOut[] = [$aTs, $bTs + $slotStep];
      }
      $rangesServer[$n] = $txt;
      $rangesTs[$n] = $tsOut;
    }

    echo json_encode([
      'ok' => true,
      'request' => [
        'date' => $date,
        'spot_id' => $spotId,
        'hall_id' => $hallId,
        'source' => 'incomingOrders.getTablesForReservation',
        'duration' => $duration,
        'guests_count' => $guests,
      ],
      'ranges_by_table_num_server' => $rangesServer,
      'ranges_ts_by_table_num' => $rangesTs,
      'server_timezone' => $apiTzName,
      'display_timezone' => $displayTzName,
      'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
  } catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
  }
  exit;
}

if (($_GET['ajax'] ?? '') === 'cap_check') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  $tableNum = trim((string)($_GET['table_num'] ?? ''));
  $guests = (int)($_GET['guests'] ?? 0);
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

  $cap = isset($tableCapsByNum[$tableNum]) ? (int)$tableCapsByNum[$tableNum] : null;
  if ($cap !== null && $cap > 0 && $guests > ($cap + 1)) {
    echo json_encode([
      'ok' => true,
      'cap' => $cap,
      'status' => 'warn',
      'message' => 'Мы подставим вам стул, но вам может быть тесно за этим столиком :)',
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

if (($_GET['ajax'] ?? '') === 'submit_booking') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
  if (!is_array($payload)) $payload = [];

  $tableNum = trim((string)($payload['table_num'] ?? ''));
  $name = trim((string)($payload['name'] ?? ''));
  $phone = trim((string)($payload['phone'] ?? ''));
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
  if ($name === '' || mb_strlen($name) > 80) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректное имя'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $phoneNorm = preg_replace('/[^\d\+\-\(\)\s]/u', '', $phone);
  $phoneNorm = trim((string)$phoneNorm);
  if ($phoneNorm === '' || mb_strlen($phoneNorm) > 40) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный номер телефона'], JSON_UNESCAPED_UNICODE);
    exit;
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
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректное время'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
  $tgChatId = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
  if ($tgChatId === '') $tgChatId = '3397075474';
  $tgThreadId = trim((string)($_ENV['TABLE_RESERVATION_THREAD_ID'] ?? ''));
  $tgThreadNum = $tgThreadId !== '' ? (int)$tgThreadId : 1938;
  if ($tgThreadNum <= 0) $tgThreadNum = 1938;
  if ($tgToken === '' || $tgChatId === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Telegram не настроен'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $text = '<b>Новая бронь с сайта</b>' . "\n";
  $text .= 'Дата: <b>' . htmlspecialchars($startDt->format('Y-m-d')) . '</b>' . "\n";
  $text .= 'Время: <b>' . htmlspecialchars($startDt->format('H:i')) . '</b>' . "\n";
  $text .= 'Кол-во человек: <b>' . htmlspecialchars((string)$guests) . '</b>' . "\n";
  $text .= 'Номер стола: <b>' . htmlspecialchars($tableNum) . '</b>' . "\n";
  $text .= 'Имя: <b>' . htmlspecialchars($name) . '</b>' . "\n";
  $text .= 'Номер телефона: <b>' . htmlspecialchars($phoneNorm) . '</b>';
  $tg = is_array($payload['tg'] ?? null) ? $payload['tg'] : [];
  $tgUid = isset($tg['user_id']) ? (int)$tg['user_id'] : 0;
  $tgUn = strtolower(trim((string)($tg['username'] ?? '')));
  $tgUn = ltrim($tgUn, '@');
  if ($tgUn !== '' || $tgUid > 0) {
    $text .= "\n";
    $text .= 'Telegram: ';
    if ($tgUn !== '') {
      $text .= '<a href="https://t.me/' . htmlspecialchars($tgUn) . '">@' . htmlspecialchars($tgUn) . '</a>';
      if ($tgUid > 0) $text .= ' · <a href="tg://user?id=' . htmlspecialchars((string)$tgUid) . '">Открыть чат</a>';
    } elseif ($tgUid > 0) {
      $text .= '<a href="tg://user?id=' . htmlspecialchars((string)$tgUid) . '">Открыть чат</a> (id ' . htmlspecialchars((string)$tgUid) . ')';
    }
  }
  $text .= "\n\n@Ollushka90 @ce_akh1  свяжитесь с гостем";

  $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
  $ok = $bot->sendMessage($text, $tgThreadNum > 0 ? $tgThreadNum : null);
  if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось отправить сообщение в Telegram'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if ($tgUid <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Telegram не привязан'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $userText = '<b>Спасибо!</b> Мы с вами свяжемся в ближайшее время.' . "\n\n";
  $userText .= '<b>Ваша бронь</b>' . "\n";
  $userText .= 'Дата: <b>' . htmlspecialchars($startDt->format('Y-m-d')) . '</b>' . "\n";
  $userText .= 'Время: <b>' . htmlspecialchars($startDt->format('H:i')) . '</b>' . "\n";
  $userText .= 'Кол-во человек: <b>' . htmlspecialchars((string)$guests) . '</b>' . "\n";
  $userText .= 'Номер стола: <b>' . htmlspecialchars($tableNum) . '</b>' . "\n";
  $userText .= 'Имя: <b>' . htmlspecialchars($name) . '</b>' . "\n";
  $userText .= 'Номер телефона: <b>' . htmlspecialchars($phoneNorm) . '</b>';

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
  if (!is_array($data) || empty($data['ok'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось отправить сообщение гостю в Telegram'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_GET['ajax'] ?? '') === 'tg_state_create') {
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

  $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
  if (!is_array($payload)) $payload = [];

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
  $returnUrl = ($host !== '' ? ($scheme . '://' . $host) : '') . '/TableReservation.php?tg_state=' . rawurlencode($code);
  $botUrl = 'https://t.me/' . rawurlencode($tgUserBot) . '?start=' . rawurlencode($code);

  echo json_encode(['ok' => true, 'code' => $code, 'bot_url' => $botUrl, 'return_url' => $returnUrl], JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_GET['ajax'] ?? '') === 'tg_state_get') {
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

?><!doctype html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Restaurant Table Map</title>
  <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
  <link rel="preconnect" href="https://api.fontshare.com">
  <link rel="preconnect" href="https://cdn.fontshare.com" crossorigin>
  <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700&f[]=clash-display@500,600&display=swap" rel="stylesheet">
  <style>
    :root {
      --text-xs: clamp(0.75rem, 0.7rem + 0.25vw, 0.875rem);
      --text-sm: clamp(0.875rem, 0.8rem + 0.35vw, 1rem);
      --text-base: clamp(1rem, 0.95rem + 0.25vw, 1.125rem);
      --text-lg: clamp(1.125rem, 1rem + 0.75vw, 1.5rem);
      --text-xl: clamp(1.5rem, 1.2rem + 1.25vw, 2.25rem);
      --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 0.75rem; --space-4: 1rem; --space-5: 1.25rem; --space-6: 1.5rem; --space-8: 2rem; --space-10: 2.5rem; --space-12: 3rem;
      --color-bg: #f5f2ea;
      --color-surface: #fcfbf7;
      --color-surface-2: #f0ece3;
      --color-border: rgba(43, 36, 28, 0.12);
      --color-text: #2b241c;
      --color-text-muted: #746a60;
      --color-primary: #7b4b2a;
      --color-primary-strong: #5f3417;
      --color-accent: #c89a63;
      --color-success: #4f7b4b;
      --shadow-sm: 0 8px 20px rgba(43, 36, 28, 0.08);
      --shadow-lg: 0 20px 60px rgba(43, 36, 28, 0.14);
      --radius-md: 16px;
      --radius-lg: 24px;
      --radius-full: 999px;
      --font-body: 'Satoshi', sans-serif;
      --font-display: 'Clash Display', 'Satoshi', sans-serif;
    }
  
    [data-theme="dark"] {
      --color-bg: #181513;
      --color-surface: #23201c;
      --color-surface-2: #2b2722;
      --color-border: rgba(255, 245, 232, 0.1);
      --color-text: #f5eee4;
      --color-text-muted: #b7ab9d;
      --color-primary: #d59c5a;
      --color-primary-strong: #f0bd7d;
      --color-accent: #7b4b2a;
      --shadow-sm: 0 8px 20px rgba(0, 0, 0, 0.22);
      --shadow-lg: 0 20px 60px rgba(0, 0, 0, 0.34);
    }
  
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body {
      font-family: var(--font-body);
      background:
        radial-gradient(circle at top left, rgba(200,154,99,.12), transparent 28%),
        radial-gradient(circle at right bottom, rgba(123,75,42,.08), transparent 24%),
        var(--color-bg);
      color: var(--color-text);
    }
  
    .app {
      min-height: 100vh;
      padding: var(--space-6);
      display: grid;
      place-items: center;
    }
  
    .panel {
      width: min(1000px, 100%);
      background: linear-gradient(180deg, rgba(255,255,255,.35), transparent), var(--color-surface);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lg);
      overflow: hidden;
    }
  
    .topbar {
      display: flex;
      justify-content: space-between;
      gap: var(--space-4);
      align-items: center;
      padding: var(--space-5) var(--space-6);
      border-bottom: 1px solid var(--color-border);
      background: rgba(255,255,255,0.24);
      backdrop-filter: blur(14px);
    }
  
    .title-wrap h1 {
      margin: 0;
      font-family: var(--font-display);
      font-size: var(--text-xl);
      line-height: 1;
      letter-spacing: -0.03em;
    }
  
    .title-wrap p {
      margin: var(--space-2) 0 0;
      color: var(--color-text-muted);
      font-size: var(--text-sm);
    }
  
    .controls {
      display: flex;
      gap: var(--space-3);
      align-items: center;
      flex-wrap: wrap;
    }
  
    .zoom {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      border: 1px solid var(--color-border);
      background: var(--color-surface-2);
      border-radius: var(--radius-full);
      padding: var(--space-2) var(--space-4);
      font-size: var(--text-sm);
      color: var(--color-text);
      white-space: nowrap;
    }
    .zoom .zbtn {
      width: 32px;
      height: 32px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.06);
      color: rgba(245,238,228,0.92);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      font-weight: 900;
      line-height: 1;
    }
    .zoom .zv { font-variant-numeric: tabular-nums; font-weight: 900; min-width: 46px; text-align: right; }
    .zoom input[type="range"] {
      width: 90px;
      height: 10px;
      accent-color: var(--color-primary);
    }
  
    .legend, .theme-toggle {
      border: 1px solid var(--color-border);
      background: var(--color-surface-2);
      border-radius: var(--radius-full);
      padding: var(--space-2) var(--space-4);
      font-size: var(--text-sm);
      color: var(--color-text);
    }
  
    .theme-toggle { cursor: pointer; }
  
    .layout {
      padding: var(--space-6);
      display: grid;
      grid-template-columns: 1fr;
      gap: var(--space-6);
    }
  
    .map-shell {
      background:
        linear-gradient(var(--color-border) 1px, transparent 1px),
        linear-gradient(90deg, var(--color-border) 1px, transparent 1px),
        var(--color-surface-2);
      background-size: 28px 28px;
      border-radius: calc(var(--radius-lg) - 8px);
      padding: 0 56px 56px 56px;
      border: 1px solid var(--color-border);
      overflow: auto;
      --map-scale: 1;
    }
  
    .map-zoom-box { width: 820px; height: 620px; }
    .map-zoom-inner { width: 820px; height: 620px; transform: scale(var(--map-scale)); transform-origin: top left; }
    .map {
      position: relative;
      width: 820px;
      height: 620px;
      border-radius: var(--radius-md);
    }

    .grass-area {
      position: absolute;
      left: -82px;
      top: 230px;
      width: 944px;
      height: 430px;
      clip-path: polygon(0 0, 100% 0, 100% 184px, 224px 184px, 224px 100%, 0 100%);
      background:
        radial-gradient(circle at 12% 24%, rgba(147,196,125,0.20), transparent 58%),
        radial-gradient(circle at 78% 30%, rgba(92,162,92,0.22), transparent 62%),
        radial-gradient(circle at 44% 78%, rgba(200,154,99,0.10), transparent 60%),
        repeating-linear-gradient(115deg, rgba(34,88,34,0.22) 0 2px, rgba(52,116,52,0.18) 2px 5px),
        repeating-linear-gradient(25deg, rgba(72,148,72,0.16) 0 3px, rgba(28,84,28,0.14) 3px 7px),
        linear-gradient(180deg, rgba(255,255,255,0.06), rgba(0,0,0,0.10)),
        rgba(52,116,52,0.12);
      background-blend-mode: screen, screen, normal, overlay, overlay, normal, normal;
      border: 1px solid rgba(255,255,255,0.12);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), inset 0 -18px 30px rgba(0,0,0,0.20);
      pointer-events: none;
      opacity: 0.98;
      filter: saturate(1.35) contrast(1.05);
    }
    .grass-area::before,
    .grass-area::after {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      clip-path: polygon(0 0, 100% 0, 100% 184px, 224px 184px, 224px 100%, 0 100%);
    }
    .grass-area::before {
      transform: rotate(-2deg) translate(-14px, 10px);
      background:
        radial-gradient(circle at 22% 58%, rgba(52,116,52,0.20), transparent 58%),
        radial-gradient(circle at 60% 18%, rgba(147,196,125,0.14), transparent 60%),
        radial-gradient(circle at 88% 62%, rgba(34,88,34,0.16), transparent 60%);
      opacity: 0.85;
      filter: blur(0.2px);
    }
    .grass-area::after {
      transform: rotate(1.4deg) translate(18px, -10px);
      background:
        radial-gradient(circle at 10% 26%, rgba(255,255,255,0.06), transparent 52%),
        radial-gradient(circle at 74% 78%, rgba(0,0,0,0.18), transparent 62%);
      opacity: 0.65;
    }
  
    .table .num {
      position: absolute;
      top: 8px;
      left: 10px;
      font-size: 1.05rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      font-family: var(--font-display);
      pointer-events: none;
      text-shadow: 0 1px 0 rgba(0,0,0,0.22);
    }
    .table .cap {
      position: absolute;
      top: 8px;
      right: 10px;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: -0.02em;
      font-family: var(--font-body);
      color: rgba(255,250,244,0.92);
      pointer-events: none;
      text-shadow: 0 1px 0 rgba(0,0,0,0.22);
      white-space: nowrap;
    }

    .grass-corner-1-7 {
      position: absolute;
      left: 15px;
      top: -230px;
      width: 850px;
      height: 900px;
      transform: scale(1.1);
      transform-origin: right bottom;
      background: url("/links/grass_corner_1_7.png?v=20260407_1720") no-repeat right bottom / 850px auto;
      pointer-events: none;
      z-index: 1;
      filter: drop-shadow(0 18px 22px rgba(0,0,0,0.22));
    }

    .bar-row {
      position: absolute;
      left: 0;
      top: 0;
      width: 820px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 55px;
      user-select: none;
      pointer-events: none;
      z-index: 5;
    }
    .bar-row * { pointer-events: auto; }

    .bar {
      width: 260px;
      min-width: 260px;
      flex: 0 0 260px;
      height: 72px;
      border-radius: 36px;
      background: linear-gradient(180deg, var(--color-primary), var(--color-primary-strong));
      color: #fff8ef;
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-size: 1.9rem;
      letter-spacing: 0.08em;
      box-shadow: var(--shadow-sm);
    }

    .side-station {
      width: 170px;
      height: 58px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.16);
      background: linear-gradient(180deg, rgba(255,255,255,0.10), rgba(0,0,0,0.10)), rgba(255,255,255,0.04);
      box-shadow: 0 12px 20px rgba(0,0,0,0.22);
      color: rgba(245,238,228,0.92);
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-size: 1.05rem;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .station-wrap {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 6px;
      pointer-events: auto;
    }
    .station-sub {
      font-family: var(--font-body);
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.02em;
      text-transform: none;
      color: rgba(245,238,228,0.62);
    }

    .station-wrap.cash {
      flex-direction: row;
      gap: 10px;
      align-items: center;
    }
    .cash-controls {
      width: 170px;
      display: grid;
      gap: 6px;
      margin-top: 0;
      padding: 8px;
      border-radius: 16px;
      border: 1px solid rgba(123, 75, 42, 0.62);
      background: rgba(0,0,0,0.12);
      box-shadow: 0 12px 22px rgba(0,0,0,0.22);
    }
    .cash-controls #resDate { display: none; }
    .cash-controls input[type="datetime-local"] {
      width: 100%;
      border-radius: 12px;
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.06);
      color: rgba(245,238,228,0.92);
      padding: 0.45rem 0.55rem;
      font-size: 12px;
      outline: none;
    }
    .cash-controls input[type="datetime-local"] { padding-left: 0.6rem; background-image: none; }
    .cash-controls .btn {
      padding: 0.52rem 0.65rem;
      font-size: 12px;
      border-radius: 12px;
      width: auto;
      min-width: 0;
    }

    .dt-btn {
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.06);
      color: rgba(245,238,228,0.92);
      padding: 0.55rem 0.65rem;
      font-size: 12px;
      border-radius: 12px;
      text-align: left;
      cursor: pointer;
    }
    .dt-btn:focus-visible {
      outline: none;
      border-color: rgba(213,156,90,0.55);
      box-shadow: 0 0 0 4px rgba(213,156,90,0.12);
    }

    .dtp {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 14px;
      z-index: 9999;
    }
    .dtp.on { display: flex; }
    .dtp-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(2px); }
    .dtp-card {
      position: relative;
      width: min(520px, 100%);
      background: linear-gradient(180deg, rgba(37, 24, 16, 0.98), rgba(17, 12, 8, 0.96));
      color: rgba(255, 250, 244, 0.94);
      border: 1px solid rgba(213,156,90,0.28);
      border-radius: 18px;
      box-shadow: 0 26px 70px rgba(0,0,0,0.45), 0 0 0 1px rgba(123, 75, 42, 0.20);
      padding: 14px 14px 12px;
      transform: translateY(8px) scale(0.98);
      opacity: 0;
      transition: opacity .18s ease, transform .18s ease;
    }
    .dtp.on .dtp-card { transform: translateY(0) scale(1); opacity: 1; }
    .dtp-title { font-weight: 900; font-size: 16px; font-family: var(--font-display); }
    .dtp-wheels { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 10px; margin-top: 12px; }
    .wheel {
      position: relative;
      border-radius: 16px;
      border: 1px solid rgba(213,156,90,0.18);
      background: rgba(255,255,255,0.03);
      overflow: hidden;
      height: 210px;
    }
    .wheel::before, .wheel::after {
      content: '';
      position: absolute;
      left: 0;
      right: 0;
      height: 40%;
      pointer-events: none;
      z-index: 2;
    }
    .wheel::before { top: 0; background: linear-gradient(180deg, rgba(17,12,8,0.96), rgba(17,12,8,0)); }
    .wheel::after { bottom: 0; background: linear-gradient(0deg, rgba(17,12,8,0.96), rgba(17,12,8,0)); }
    .wheel-mid {
      position: absolute;
      left: 12px;
      right: 12px;
      top: 50%;
      height: 40px;
      transform: translateY(-50%);
      border-top: 1px solid rgba(213,156,90,0.22);
      border-bottom: 1px solid rgba(213,156,90,0.22);
      border-radius: 12px;
      background: rgba(213,156,90,0.06);
      z-index: 1;
      pointer-events: none;
    }
    .wheel-list {
      position: absolute;
      inset: 0;
      overflow-y: auto;
      scroll-snap-type: y mandatory;
      -webkit-overflow-scrolling: touch;
      padding: 85px 0;
      scrollbar-width: none;
    }
    .wheel-list::-webkit-scrollbar { display: none; }
    .wheel-item {
      height: 40px;
      display: flex;
      align-items: center;
      justify-content: center;
      scroll-snap-align: center;
      font-family: var(--font-display);
      font-weight: 700;
      letter-spacing: -0.01em;
      color: rgba(245, 238, 228, 0.58);
      font-size: 14px;
      padding: 0 10px;
      text-align: center;
      user-select: none;
    }
    .wheel-item.active { color: rgba(255, 250, 244, 0.94); }
    .dtp-actions .btn {
      transition: transform .14s ease, filter .14s ease, box-shadow .14s ease;
    }
    .dtp-actions .btn:hover {
      transform: translateY(-1px);
      filter: saturate(1.05);
      box-shadow: 0 10px 20px rgba(0,0,0,0.28);
    }
    .dtp-actions .btn:active { transform: translateY(0); box-shadow: none; }
    .dtp-actions .btn:focus-visible {
      outline: none;
      box-shadow: 0 0 0 4px rgba(213,156,90,0.18);
    }
    .dtp-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; margin-top: 12px; }
    body { overflow-x: hidden; }
    .mini-loader {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      border: 2px solid rgba(245,238,228,0.25);
      border-top-color: rgba(245,238,228,0.75);
      display: inline-block;
      vertical-align: middle;
      margin-left: 6px;
      animation: miniSpin 0.8s linear infinite;
    }
    @keyframes miniSpin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    .fountain {
      position: absolute;
      width: 84px;
      height: 84px;
      border-radius: 50%;
      background:
        radial-gradient(circle at 50% 50%, rgba(255,255,255,0.40), rgba(255,255,255,0.08) 38%, transparent 58%),
        radial-gradient(circle at 50% 55%, rgba(90,180,255,0.62), rgba(35,110,180,0.34) 55%, rgba(10,40,70,0.20) 100%),
        rgba(35,110,180,0.18);
      border: 1px solid rgba(255,255,255,0.16);
      box-shadow: 0 16px 30px rgba(0,0,0,0.22), inset 0 2px 8px rgba(255,255,255,0.10);
      pointer-events: none;
      z-index: 1;
    }
    .fountain svg {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0.92;
      filter: drop-shadow(0 8px 16px rgba(0,0,0,0.22));
      pointer-events: none;
    }
    .koi {
      position: absolute;
      left: 50%;
      top: 50%;
      width: 16px;
      height: 10px;
      border-radius: 999px;
      background:
        radial-gradient(circle at 25% 45%, rgba(255,255,255,0.92), rgba(255,255,255,0) 52%),
        radial-gradient(circle at 60% 55%, rgba(255,255,255,0.20), rgba(255,255,255,0) 62%),
        linear-gradient(180deg, #ffb14a, #e87422);
      box-shadow: 0 6px 10px rgba(0,0,0,0.18);
      transform-origin: center;
      opacity: 0.95;
    }
    .koi::before {
      content: '';
      position: absolute;
      left: 2px;
      top: 50%;
      width: 3px;
      height: 3px;
      border-radius: 50%;
      background: rgba(0,0,0,0.5);
      transform: translateY(-50%);
    }
    .koi::after {
      content: '';
      position: absolute;
      right: -6px;
      top: 50%;
      width: 10px;
      height: 10px;
      background: linear-gradient(180deg, rgba(255,190,90,0.9), rgba(232,116,34,0.9));
      clip-path: polygon(0 50%, 100% 0, 100% 100%);
      transform: translateY(-50%);
      border-radius: 2px;
      opacity: 0.95;
    }
    .koi.koi-1 { animation: koiOrbit1 10.4s linear infinite; }
    .koi.koi-2 {
      animation: koiOrbit2 9.3s linear infinite;
      filter: hue-rotate(-8deg) saturate(1.1);
      opacity: 0.92;
    }
    @keyframes koiOrbit1 {
      from { transform: translate(-50%, -50%) rotate(0deg) translate(22px) rotate(270deg); }
      to { transform: translate(-50%, -50%) rotate(360deg) translate(22px) rotate(270deg); }
    }
    @keyframes koiOrbit2 {
      from { transform: translate(-50%, -50%) rotate(180deg) translate(20px) rotate(90deg); }
      to { transform: translate(-50%, -50%) rotate(-180deg) translate(20px) rotate(90deg); }
    }
  
    .table {
      position: absolute;
      width: 74px;
      height: 74px;
      border: 1px solid rgba(255,255,255,.24);
      border-radius: 22px;
      background: linear-gradient(180deg, #b58a63, #8b5e3b);
      color: #fffaf4;
      box-shadow: 0 14px 24px rgba(84, 49, 20, .22);
      z-index: 2;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      font-family: var(--font-display);
      font-size: 1.45rem;
      font-weight: 600;
      letter-spacing: -0.03em;
      cursor: pointer;
      transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
      user-select: none;
      transform-origin: center;
      padding: 6px 4px 8px;
    }
  
    .table:hover, .table:focus-visible {
      transform: translateY(-3px) scale(1.02);
      box-shadow: 0 18px 34px rgba(84, 49, 20, .3);
      filter: saturate(1.05);
      outline: none;
    }
  
    .table .res-time {
      position: absolute;
      top: 34px;
      left: 10px;
      right: 10px;
      font-size: 0.6rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: rgba(245, 238, 228, 0.72);
      font-family: var(--font-body);
      pointer-events: none;
      text-shadow: 0 1px 0 rgba(0,0,0,0.22);
      line-height: 1.1;
      text-align: center;
      white-space: pre-line;
      overflow: hidden;
      text-overflow: ellipsis;
      transition: color .14s ease;
    }
    .table:hover .res-time, .table:focus-visible .res-time {
      color: rgba(255, 64, 64, 1);
    }

    .table.disabled {
      background: linear-gradient(180deg, rgba(120, 120, 120, 0.78), rgba(55, 55, 55, 0.86));
      filter: grayscale(1) brightness(0.75);
      cursor: not-allowed;
    }

    .table.busy { cursor: not-allowed; }

    .table.disabled:hover, .table.disabled:focus-visible,
    .table.busy:hover, .table.busy:focus-visible {
      transform: none;
      box-shadow: 0 14px 24px rgba(84, 49, 20, .22);
      filter: none;
    }
  
    .table.small-vertical { width: 75px; height: 92px; border-radius: 18px; }
    .table.small-vertical.wide-1 { width: 86px; }
    .table.wide { width: 112px; height: 58px; border-radius: 18px; }
    .table.large { width: 108px; height: 108px; border-radius: 26px; }

    .table-toast {
      position: fixed;
      left: 0;
      top: 0;
      z-index: 9999;
      width: min(340px, calc(100vw - 24px));
      background: rgba(17, 24, 39, 0.94);
      color: rgba(255, 250, 244, 0.94);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 14px;
      box-shadow: 0 18px 40px rgba(0,0,0,0.35);
      padding: 10px 12px;
      transform: translate(-50%, calc(-100% - 12px)) scale(0.98);
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.16s ease, transform 0.16s ease;
    }

    .table-toast.on {
      opacity: 1;
      transform: translate(-50%, calc(-100% - 12px)) scale(1);
    }

    .table-toast .t-title { font-weight: 900; font-size: 13px; }
    .table-toast .t-reason { margin-top: 6px; font-size: 12px; color: rgba(245, 238, 228, 0.78); }
    .table-toast .t-reason b { color: rgba(255, 250, 244, 0.94); }
  
    .sidebar {
      display: grid;
      gap: var(--space-4);
      align-content: start;
    }
  
    .card {
      background: var(--color-surface-2);
      border: 1px solid var(--color-border);
      border-radius: calc(var(--radius-lg) - 8px);
      padding: var(--space-5);
      box-shadow: var(--shadow-sm);
    }
  
    .card h2 {
      margin: 0 0 var(--space-3);
      font-size: var(--text-lg);
      font-family: var(--font-display);
      line-height: 1.1;
    }
  
    .card p, .card li {
      color: var(--color-text-muted);
      font-size: var(--text-sm);
      margin: 0;
    }
  
    label {
      display: grid;
      gap: var(--space-2);
      font-size: var(--text-sm);
      color: var(--color-text-muted);
      margin-top: var(--space-3);
    }

    .dt-row {
      display: flex;
      gap: var(--space-3);
      align-items: flex-end;
      margin-top: var(--space-3);
    }
    .dt-row label { margin-top: 0; }
    .dt-label { flex: 1 1 auto; }
    .dt-dur { flex: 0 0 34%; }
    input[type="datetime-local"], input[type="number"] {
      width: 100%;
      border-radius: 14px;
      border: 1px solid var(--color-border);
      background: rgba(255,255,255,0.04);
      color: var(--color-text);
      padding: 0.85rem 0.9rem;
      font-size: var(--text-sm);
      outline: none;
    }
    input[type="datetime-local"] {
      padding-left: 2.4rem;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none'%3E%3Cpath d='M8 3v3M16 3v3' stroke='rgba(245,238,228,0.72)' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M4 9h16' stroke='rgba(245,238,228,0.38)' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M6 6h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z' stroke='rgba(245,238,228,0.48)' stroke-width='2'/%3E%3Cpath d='M8 13h2M12 13h2M16 13h0M8 17h2M12 17h2' stroke='rgba(245,238,228,0.62)' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: 0.85rem 50%;
      background-size: 18px 18px;
    }
    input[type="number"] { padding-left: 2.4rem; }
    input[type="number"] {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none'%3E%3Cpath d='M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2' stroke='rgba(245,238,228,0.48)' stroke-width='2' stroke-linecap='round'/%3E%3Ccircle cx='9' cy='7' r='4' stroke='rgba(245,238,228,0.62)' stroke-width='2'/%3E%3Cpath d='M22 21v-2a4 4 0 0 0-3-3.87' stroke='rgba(245,238,228,0.38)' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M19 3.13a4 4 0 0 1 0 7.75' stroke='rgba(245,238,228,0.38)' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: 0.85rem 50%;
      background-size: 18px 18px;
    }
    input[type="datetime-local"]:focus, input[type="number"]:focus {
      border-color: rgba(213,156,90,0.55);
      box-shadow: 0 0 0 4px rgba(213,156,90,0.12);
    }
    textarea {
      width: 100%;
      min-height: 220px;
      border-radius: 14px;
      border: 1px solid var(--color-border);
      background: rgba(0,0,0,0.12);
      color: var(--color-text);
      padding: 0.85rem 0.9rem;
      font-size: 12px;
      line-height: 1.35;
      resize: vertical;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }

    .selected-output {
      margin-top: var(--space-4);
      padding: var(--space-4);
      border-radius: var(--radius-md);
      background: rgba(123,75,42,.08);
      color: var(--color-text);
      min-height: 74px;
    }
  
    .selected-list {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin-top: var(--space-3);
    }
  
    .pill {
      background: rgba(123,75,42,.12);
      color: var(--color-primary-strong);
      padding: 0.45rem 0.8rem;
      border-radius: var(--radius-full);
      font-size: var(--text-sm);
      font-weight: 700;
    }
  
    .actions {
      display: flex;
      gap: var(--space-3);
      flex-wrap: wrap;
      margin-top: var(--space-4);
    }

    .guest-row {
      display: flex;
      gap: var(--space-3);
      align-items: flex-end;
      margin-top: var(--space-3);
    }
    .guest-label { flex: 0 0 40%; }
    #checkBtn { flex: 1 1 auto; }
    @media (max-width: 520px) {
      .guest-row { flex-direction: column; align-items: stretch; }
      .guest-label { flex-basis: auto; }
    }

    .modal { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; padding: 14px; z-index: 9998; }
    .modal.on { display: flex; }
    .modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(2px); }
    .modal-card {
      position: relative;
      width: min(520px, 100%);
      background: rgba(17, 24, 39, 0.96);
      color: rgba(255, 250, 244, 0.94);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 18px;
      box-shadow: 0 26px 70px rgba(0,0,0,0.45);
      padding: 14px 14px 12px;
      transform: translateY(8px) scale(0.98);
      opacity: 0;
      transition: opacity .18s ease, transform .18s ease;
    }
    .modal.on .modal-card { transform: translateY(0) scale(1); opacity: 1; }
    .modal-title { font-weight: 900; font-size: 16px; font-family: var(--font-display); }
    .modal-text { margin-top: 10px; color: rgba(245, 238, 228, 0.78); font-size: var(--text-sm); line-height: 1.35; }
    .modal-actions { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; margin-top: 12px; }
    .modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
    .modal-label { display: grid; gap: 6px; font-size: 12px; color: rgba(245, 238, 228, 0.78); margin-top: 0; }
    .modal input[type="text"], .modal input[type="tel"], .modal input[type="number"] {
      width: 100%;
      border-radius: 14px;
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.06);
      color: rgba(255, 250, 244, 0.94);
      padding: 0.75rem 0.85rem;
      font-size: var(--text-sm);
      outline: none;
    }
    #reqGuests { max-width: 160px; }
    .msgr {
      margin-top: 12px;
      padding: 10px 12px;
      border-radius: 14px;
      border: 1px solid rgba(255, 255, 255, 0.10);
      background: rgba(255, 255, 255, 0.04);
    }
    .msgr-title {
      font-weight: 900;
      letter-spacing: 0.02em;
      font-size: 11px;
      color: rgba(245, 238, 228, 0.78);
    }
    .msgr-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
    .msgr-btn {
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.14);
      background: rgba(255,255,255,0.06);
      color: rgba(255, 250, 244, 0.94);
      width: 44px;
      height: 44px;
      padding: 0;
      font-weight: 800;
      font-size: 12px;
      cursor: pointer;
      transition: transform .14s ease, filter .14s ease, opacity .14s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .msgr-btn svg { width: 22px; height: 22px; }
    .msgr-btn:hover { transform: translateY(-1px); filter: saturate(1.05); }
    .msgr-btn:disabled { opacity: 0.35; cursor: default; transform: none; }
    .msgr-hint {
      margin-top: 10px;
      font-size: 12px;
      line-height: 1.35;
      color: rgba(245, 238, 228, 0.78);
    }
    .modal-hint {
      margin-top: 10px;
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.10);
      border-radius: 14px;
      padding: 10px 12px;
      font-size: 12px;
      line-height: 1.35;
      color: rgba(255, 250, 244, 0.90);
    }

    .modal-hint.warn {
      border-color: rgba(255, 88, 88, 0.55);
      box-shadow: 0 0 0 3px rgba(255, 88, 88, 0.10);
    }

    .modal-hint .hint-text {
      display: inline-block;
      background-image: linear-gradient(90deg, rgba(255, 190, 140, 0.95), rgba(255, 88, 88, 0.95), rgba(255, 225, 170, 0.95));
      background-size: 220% 100%;
      background-position: 0% 50%;
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
      animation: shimmerHint 2.4s ease-in-out infinite;
    }

    @keyframes shimmerHint {
      0% { background-position: 0% 50%; }
      50% { background-position: 100% 50%; }
      100% { background-position: 0% 50%; }
    }
    .modal-note { margin-top: 10px; color: rgba(245, 238, 228, 0.70); font-size: 12px; line-height: 1.35; }
    @media (max-width: 560px) { .modal-grid { grid-template-columns: 1fr; } }
  
    .btn {
      border: 0;
      border-radius: var(--radius-full);
      padding: 0.9rem 1rem;
      font-size: var(--text-sm);
      font-weight: 700;
      cursor: pointer;
    }
  
    .btn-primary { background: var(--color-primary); color: #fffaf4; }
    .btn-secondary { background: transparent; color: var(--color-text); border: 1px solid var(--color-border); }
    .btn-primary.is-disabled {
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.12);
      color: rgba(255, 250, 244, 0.55);
      opacity: 0.7;
      cursor: not-allowed;
    }
  
    .stats { display: none; }
    .stat { display: none; }
    .footer-note { display: none; }

    .table.free {
      outline: 2px solid rgba(79,123,75,0.85);
      outline-offset: 2px;
    }
    .table.busy {
      filter: grayscale(0.85) brightness(0.82);
    }
  
    @media (max-width: 980px) {
      .layout { grid-template-columns: 1fr; }
    }
  
    @media (max-width: 640px) {
      .app {
        padding: calc(env(safe-area-inset-top) + var(--space-3)) var(--space-3) calc(env(safe-area-inset-bottom) + var(--space-3));
        min-height: auto;
        display: block;
      }
      .layout, .map-shell { padding: var(--space-4); }
      .topbar { padding: var(--space-4); align-items: flex-start; flex-direction: column; }
      .map-shell { padding: 0 28px 28px 28px; }
      .zoom { width: 100%; justify-content: space-between; }
    }
  </style>
</head>
<body>
  <div class="app">
    <main class="panel">
      <div class="topbar">
        <div class="title-wrap">
          <h1>Схема бронирования</h1>
        </div>
        <div class="cash-controls">
          <input type="datetime-local" id="resDate" aria-label="Дата и время">
          <button type="button" class="dt-btn" id="resDateBtn">Выбрать дату</button>
          <button class="btn btn-primary" id="checkBtn" type="button">Проверить столики</button>
        </div>
        <div class="controls">
          <label class="zoom" aria-label="Масштаб схемы">
            <span>Масштаб</span>
            <button class="zbtn" type="button" id="mapZoomMinus" aria-label="Уменьшить масштаб">−</button>
            <span class="zv" id="mapZoomVal">100%</span>
            <button class="zbtn" type="button" id="mapZoomPlus" aria-label="Увеличить масштаб">+</button>
            <input id="mapZoomRange" type="range" min="50" max="200" step="1" value="100" aria-label="Ползунок масштаба">
          </label>
          <button class="theme-toggle" type="button" data-theme-toggle aria-label="Переключить тему">☀️</button>
        </div>
      </div>
  
      <section class="layout">
        <div class="map-shell">
            <div class="map-zoom-box" id="mapZoomBox">
            <div class="map-zoom-inner" id="mapZoomInner">
              <div class="map" aria-label="Схема столов ресторана">
            <div class="grass-corner-1-7" aria-hidden="true"></div>
            <button class="table large" style="left: 712px; top: 236px;" data-table="1"><span class="num">1</span><span class="cap"></span></button>
            <button class="table large" style="left: 712px; top: 362px;" data-table="2"><span class="num">2</span><span class="cap"></span></button>
            <button class="table large" style="left: 712px; top: 488px;" data-table="3"><span class="num">3</span><span class="cap"></span></button>
  
            <button class="table small-vertical wide-1" style="left: 534px; top: 528px;" data-table="4"><span class="num">4</span><span class="cap"></span></button>
            <button class="table small-vertical wide-1" style="left: 370px; top: 528px;" data-table="5"><span class="num">5</span><span class="cap"></span></button>
            <button class="table small-vertical wide-1" style="left: 222px; top: 528px;" data-table="6"><span class="num">6</span><span class="cap"></span></button>
            <button class="table large" style="left: 12px; top: 512px;" data-table="7"><span class="num">7</span><span class="cap"></span></button>
  
            <button class="table wide" style="left: 422px; top: 420px;" data-table="8"><span class="num">8</span><span class="cap"></span></button>
            <button class="table wide" style="left: 300px; top: 420px;" data-table="9"><span class="num">9</span><span class="cap"></span></button>
            <div class="fountain" style="left: 532px; top: 316px;" aria-hidden="true">
              <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <defs>
                  <linearGradient id="fWat" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="rgba(255,255,255,0.85)"/>
                    <stop offset="1" stop-color="rgba(90,180,255,0.10)"/>
                  </linearGradient>
                  <linearGradient id="fBowl" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="rgba(255,255,255,0.35)"/>
                    <stop offset="1" stop-color="rgba(0,0,0,0.35)"/>
                  </linearGradient>
                </defs>
                <circle cx="32" cy="44" r="16" fill="rgba(35,110,180,0.20)" stroke="rgba(255,255,255,0.18)" stroke-width="1"/>
                <path d="M18 44c4-6 24-6 28 0" stroke="rgba(255,255,255,0.28)" stroke-width="2" stroke-linecap="round"/>
                <path d="M22 44c3-4 17-4 20 0" stroke="rgba(90,180,255,0.30)" stroke-width="2" stroke-linecap="round"/>
                <path d="M32 18c0 10-6 12-6 20" stroke="url(#fWat)" stroke-width="3" stroke-linecap="round"/>
                <path d="M32 18c0 10 6 12 6 20" stroke="url(#fWat)" stroke-width="3" stroke-linecap="round"/>
                <path d="M32 14c0 10 0 14 0 24" stroke="rgba(255,255,255,0.78)" stroke-width="3" stroke-linecap="round"/>
                <circle cx="32" cy="14" r="3" fill="rgba(255,255,255,0.75)"/>
                <path d="M24 40h16c0 0 2 0 2 2s-2 2-2 2H24c0 0-2 0-2-2s2-2 2-2Z" fill="url(#fBowl)" stroke="rgba(255,255,255,0.16)" stroke-width="1"/>
              </svg>
              <div class="koi koi-1"></div>
              <div class="koi koi-2"></div>
            </div>
            <button class="table wide" style="left: 102px; top: 420px;" data-table="10"><span class="num">10</span><span class="cap"></span></button>
            <button class="table wide" style="left: -20px; top: 420px;" data-table="11"><span class="num">11</span><span class="cap"></span></button>
  
            <button class="table" style="left: 402px; top: 304px;" data-table="12"><span class="num">12</span><span class="cap"></span></button>
            <button class="table" style="left: 274px; top: 304px;" data-table="13"><span class="num">13</span><span class="cap"></span></button>
            <button class="table" style="left: 162px; top: 304px;" data-table="14"><span class="num">14</span><span class="cap"></span></button>
  
            <button class="table small-vertical" style="left: 532px; top: 192px;" data-table="15"><span class="num">15</span><span class="cap"></span></button>
            <button class="table small-vertical" style="left: 417px; top: 192px;" data-table="16"><span class="num">16</span><span class="cap"></span></button>
            <button class="table small-vertical" style="left: 306px; top: 192px;" data-table="17"><span class="num">17</span><span class="cap"></span></button>
            <button class="table small-vertical" style="left: 194px; top: 192px;" data-table="18"><span class="num">18</span><span class="cap"></span></button>
            <button class="table small-vertical" style="left: 82px; top: 192px;" data-table="19"><span class="num">19</span><span class="cap"></span></button>
            <button class="table large" style="left: -46px; top: 254px;" data-table="20"><span class="num">20</span><span class="cap"></span></button>
  
            <div class="bar-row">
              <div class="station-wrap">
                <div class="side-station">Музыканты</div>
                <div class="station-sub"><span id="busyDateLabel">Данные на —</span><span class="mini-loader" id="busyDateLoader" hidden></span></div>
              </div>
              <div class="bar">BAR</div>
              <div class="station-wrap cash">
                <div class="side-station">Касса</div>
              </div>
            </div>
              </div>
            </div>
          </div>
      </section>
    </main>
  </div>

  <div class="dtp" id="dtpModal" aria-hidden="true">
    <div class="dtp-backdrop" data-dtp-close></div>
    <div class="dtp-card" role="dialog" aria-modal="true" aria-labelledby="dtpTitle">
      <div class="dtp-title" id="dtpTitle">Выбор даты и времени</div>
      <div class="dtp-wheels">
        <div class="wheel">
          <div class="wheel-mid"></div>
          <div class="wheel-list" id="dtpDateList"></div>
        </div>
        <div class="wheel">
          <div class="wheel-mid"></div>
          <div class="wheel-list" id="dtpTimeList"></div>
        </div>
      </div>
      <div class="dtp-actions">
        <button class="btn btn-secondary" type="button" data-dtp-close>Отмена</button>
        <button class="btn btn-primary" type="button" id="dtpOk">Ок</button>
      </div>
    </div>
  </div>

  <div class="modal" id="capModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close="capModal"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="capModalTitle">
      <div class="modal-title" id="capModalTitle">Подтвердите</div>
      <div class="modal-text" id="capModalText"></div>
      <div class="modal-actions">
        <button class="btn btn-secondary" type="button" id="capModalNo">Нет</button>
        <button class="btn btn-primary" type="button" id="capModalYes">Да</button>
      </div>
    </div>
  </div>

  <div class="modal" id="reqModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close="reqModal"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="reqModalTitle">
      <div class="modal-title" id="reqModalTitle">Заявка на бронь на столик <span id="reqModalTable"></span></div>
      <form id="reqForm">
        <div class="modal-grid">
          <label class="modal-label">
            Ваше имя
            <input type="text" id="reqName" autocomplete="name">
          </label>
          <label class="modal-label">
            Ваш номер телефона
            <input type="tel" id="reqPhone" autocomplete="tel">
          </label>
          <label class="modal-label">
            Кол-во гостей
            <input type="number" id="reqGuests" min="1" max="99">
          </label>
          <label class="modal-label">
            Время старта брони
            <input type="text" id="reqStart" readonly>
          </label>
        </div>
        <div class="msgr">
          <div class="msgr-title">ВАШ МЕССЕНДЖЕР</div>
          <div class="msgr-row">
            <button type="button" class="msgr-btn" id="msgrTgBtn" aria-label="Telegram" title="Telegram">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M20.6 5.3 4.2 11.7c-1.1.4-1.1 1-.2 1.3l4.2 1.3 1.6 4.8c.2.6.4.6.8.2l2.3-2.2 4.7 3.4c.9.5 1.5.2 1.7-.8l2.8-13.1c.3-1.2-.4-1.7-1.5-1.3Z" fill="currentColor" opacity=".9"/>
                <path d="M9.1 14.9 18.3 8.9c.5-.3.9-.1.5.2l-7.6 6.9-.3 2.9c0 .4-.2.5-.4.1l-1.5-4.8Z" fill="currentColor"/>
              </svg>
            </button>
            <button type="button" class="msgr-btn" aria-label="WhatsApp (скоро)" title="WhatsApp (скоро)" disabled>
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M12 3.2a8.8 8.8 0 0 0-7.5 13.5l-.9 3.3 3.4-.9A8.8 8.8 0 1 0 12 3.2Z" fill="currentColor" opacity=".9"/>
                <path d="M10.2 8.7c.2-.4.4-.4.7-.4h.5c.2 0 .4 0 .6.4l.7 1.6c.1.3.1.5-.1.7l-.5.5c-.2.2-.2.4-.1.6.4.8 1 1.5 1.7 2 .2.2.4.2.6.1l.6-.3c.2-.1.5-.1.7 0l1.4.7c.4.2.4.4.4.6 0 .2 0 .5-.1.7-.2.5-1 1-1.6 1.1-.5.1-1.1.1-2.6-.6-1.8-.8-3.2-2.6-3.7-3.4-.5-.9-.9-2-.1-3.3Z" fill="#0b0f14"/>
              </svg>
            </button>
            <button type="button" class="msgr-btn" aria-label="Zalo (скоро)" title="Zalo (скоро)" disabled>
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="3.2" y="3.2" width="17.6" height="17.6" rx="6" fill="currentColor" opacity=".9"/>
                <path d="M7.6 16.6v-1.2l5.3-6.3H7.7V7.4h8.7v1.2l-5.3 6.3h5.4v1.7H7.6Z" fill="#0b0f14"/>
              </svg>
            </button>
          </div>
          <div class="msgr-hint" id="msgrHint" hidden></div>
        </div>
        <div class="modal-hint" id="reqHint" hidden></div>
        <div class="modal-note">Бронь держится 30 мин с момента старта. Если гость не пришел через 30 мин после начала — бронь аннулируется.</div>
        <div class="modal-actions">
          <button class="btn btn-secondary" type="button" data-modal-close="reqModal">Закрыть</button>
          <button class="btn btn-primary" type="submit" id="reqSubmit">Отправить</button>
        </div>
      </form>
    </div>
  </div>

  <div class="table-toast" id="tableToast" aria-live="polite" aria-atomic="true">
    <div class="t-title" id="toastTitle"></div>
    <div class="t-reason" id="toastReason"></div>
  </div>
  
  <script>
    const root = document.documentElement;
    const toggle = document.querySelector('[data-theme-toggle]');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    root.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
    toggle.textContent = prefersDark ? '☀️' : '🌙';

    const mapShell = document.querySelector('.map-shell');
    const mapZoomVal = document.getElementById('mapZoomVal');
    const mapZoomMinus = document.getElementById('mapZoomMinus');
    const mapZoomPlus = document.getElementById('mapZoomPlus');
    const mapZoomRange = document.getElementById('mapZoomRange');

    const applyMapZoom = (pct, keepAnchor) => {
      if (!mapShell) return;
      const p = Math.max(50, Math.min(200, Number(pct || 100) || 100));
      const scale = p / 100;
      if (mapZoomVal) mapZoomVal.textContent = String(Math.round(p)) + '%';
      if (mapZoomRange) mapZoomRange.value = String(Math.round(p));

      let ax = 0, ay = 0;
      if (keepAnchor) {
        const old = Number(getComputedStyle(mapShell).getPropertyValue('--map-scale')) || 1;
        const rect = mapShell.getBoundingClientRect();
        ax = (mapShell.scrollLeft + rect.width / 2) / old;
        ay = (mapShell.scrollTop + rect.height / 2) / old;
      }

      mapShell.style.setProperty('--map-scale', String(scale));

      if (keepAnchor) {
        const rect = mapShell.getBoundingClientRect();
        mapShell.scrollLeft = Math.max(0, ax * scale - rect.width / 2);
        mapShell.scrollTop = Math.max(0, ay * scale - rect.height / 2);
      }
    };

    applyMapZoom(100, false);
    const getCurrentZoomPct = () => {
      if (!mapShell) return 100;
      const cur = Number(getComputedStyle(mapShell).getPropertyValue('--map-scale')) || 1;
      return Math.round(cur * 100);
    };
    if (mapZoomMinus) mapZoomMinus.addEventListener('click', () => applyMapZoom(getCurrentZoomPct() - 5, true));
    if (mapZoomPlus) mapZoomPlus.addEventListener('click', () => applyMapZoom(getCurrentZoomPct() + 5, true));
    if (mapZoomRange) mapZoomRange.addEventListener('input', () => applyMapZoom(mapZoomRange.value, true));
  
    toggle.addEventListener('click', () => {
      const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      toggle.textContent = next === 'dark' ? '☀️' : '🌙';
    });
  
    const defaultResDateLocal = <?= json_encode($defaultResDateLocal, JSON_UNESCAPED_UNICODE) ?>;
    const allowedTableNums = <?= json_encode($allowedSchemeNums, JSON_UNESCAPED_UNICODE) ?>;
    const tableCapsByNum = <?= json_encode($tableCapsByNum, JSON_UNESCAPED_UNICODE) ?>;
    const allowedSet = Array.isArray(allowedTableNums) ? new Set(allowedTableNums.map((x) => String(x))) : null;

    const tables = Array.from(document.querySelectorAll('.table'));
    const shiftTablesUp = (px) => {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        const num = Number(n);
        if (!isFinite(num) || num < 1 || num > 20) return;
        const topStr = String(t.style.top || '').trim();
        const m = topStr.match(/^(-?\d+(?:\.\d+)?)px$/);
        if (!m) return;
        const cur = Number(m[1]);
        if (!isFinite(cur)) return;
        t.style.top = String(cur - px) + 'px';
      });
    };
    const shiftTablesRight = (fromNum, toNum, px) => {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        const num = Number(n);
        if (!isFinite(num) || num < fromNum || num > toNum) return;
        const leftStr = String(t.style.left || '').trim();
        const m = leftStr.match(/^(-?\d+(?:\.\d+)?)px$/);
        if (!m) return;
        const cur = Number(m[1]);
        if (!isFinite(cur)) return;
        t.style.left = String(cur + px) + 'px';
      });
    };
    shiftTablesUp(56);
    shiftTablesRight(15, 19, 28);
    if (allowedSet !== null && allowedSet.size > 0) {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        if (!allowedSet.has(n)) {
          t.classList.add('disabled');
        }
      });
    }

    tables.forEach((t) => {
      const n = String(t.dataset.table || '');
      const capEl = t.querySelector('.cap');
      const cap = tableCapsByNum && typeof tableCapsByNum === 'object' && tableCapsByNum[n] != null ? Number(tableCapsByNum[n]) : null;
      if (capEl) capEl.textContent = (cap != null && isFinite(cap)) ? (String(Math.max(0, Math.floor(cap))) + ' 👤') : '';
    });

    const setBusyLabel = (dateStr) => {
      const busyDateLabel = document.getElementById('busyDateLabel');
      if (busyDateLabel) busyDateLabel.textContent = 'Данные на ' + String(dateStr || '—');
    };
    const setBusyLoader = (isOn) => {
      const busyDateLoader = document.getElementById('busyDateLoader');
      if (!busyDateLoader) return;
      busyDateLoader.hidden = !isOn;
      busyDateLoader.style.display = isOn ? 'inline-block' : 'none';
    };

    const clearReservationsOnTables = () => {
      tables.forEach((t) => {
        const el = t.querySelector('.res-time');
        if (el) el.remove();
      });
    };

    let lastReservationsByTable = {};
    const applyReservationsItemsToTables = (items, dateStr, dtValue) => {
      const list = Array.isArray(items) ? items : [];
      const day = String(dateStr || '').slice(0, 10);
      if (!day) return;

      const dt = String(dtValue || '').trim();
      const selMin = (() => {
        const m = dt.match(/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/);
        if (!m) return null;
        const hh = Number(m[1]);
        const mm = Number(m[2]);
        if (!isFinite(hh) || !isFinite(mm)) return null;
        return (hh * 60) + mm;
      })();
      const today = new Date();
      const todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
      const isToday = day === todayStr;
      const durMin = 120;

      const byTable = {};
      list.forEach((it) => {
        if (!it || typeof it !== 'object') return;
        const t = String(it.table_title ?? '').trim();
        const s = String(it.date_start ?? '').trim();
        const e = String(it.date_end ?? '').trim();
        if (!t || !s || !e) return;
        if (s.slice(0, 10) !== day) return;
        const sm = Number(s.slice(11, 13)) * 60 + Number(s.slice(14, 16));
        const em = Number(e.slice(11, 13)) * 60 + Number(e.slice(14, 16));
        if (!isFinite(sm) || !isFinite(em)) return;
        if (!byTable[t]) byTable[t] = [];
        byTable[t].push([sm, em]);
      });

      Object.keys(byTable).forEach((k) => {
        const arr = byTable[k].slice().sort((a, b) => a[0] - b[0]);
        const merged = [];
        arr.forEach(([s, e]) => {
          if (!merged.length) { merged.push([s, e]); return; }
          const last = merged[merged.length - 1];
          if (s <= last[1]) last[1] = Math.max(last[1], e);
          else merged.push([s, e]);
        });
        byTable[k] = merged;
      });

      const pad2 = (x) => String(x).padStart(2, '0');
      const fmt = (m) => pad2(Math.floor(m / 60)) + ':' + pad2(m % 60);

      lastReservationsByTable = byTable;

      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        const ranges = Array.isArray(byTable[n]) ? byTable[n] : [];
        const selEnd = selMin != null ? (selMin + durMin) : null;
        const overlapsSel = (selMin != null && selEnd != null)
          ? ranges.some(([s, e]) => s < selEnd && e > selMin)
          : false;
        let txt = ranges.length ? ranges.slice(0, 2).map(([s, e]) => fmt(s) + '-' + fmt(e)).join(' · ') : '';
        if (isToday && selMin != null && !overlapsSel && last && !freeNums.has(n)) {
          txt = 'занят\nсейчас';
        }
        let el = t.querySelector('.res-time');
        if (!txt) {
          if (el) el.remove();
          return;
        }
        if (!el) {
          el = document.createElement('div');
          el.className = 'res-time';
        }
        el.textContent = txt;
        if (!t.contains(el)) t.appendChild(el);
      });
    };
    const resDate = document.getElementById('resDate');
    const resDateBtn = document.getElementById('resDateBtn');
    const checkBtn = document.getElementById('checkBtn');
    const resultText = document.getElementById('resultText');
    const selectedTableEl = document.getElementById('selectedTable');
    const statusLine = document.getElementById('statusLine');
    const stepGuests = document.getElementById('stepGuests');
    const stepCheck = document.getElementById('stepCheck');
    const capModal = document.getElementById('capModal');
    const capModalText = document.getElementById('capModalText');
    const capModalYes = document.getElementById('capModalYes');
    const capModalNo = document.getElementById('capModalNo');
    const reqModal = document.getElementById('reqModal');
    const reqForm = document.getElementById('reqForm');
    const reqName = document.getElementById('reqName');
    const reqPhone = document.getElementById('reqPhone');
    const reqModalTable = document.getElementById('reqModalTable');
    const reqGuests = document.getElementById('reqGuests');
    const reqStart = document.getElementById('reqStart');
    const reqHint = document.getElementById('reqHint');
    const reqSubmit = document.getElementById('reqSubmit');
    const msgrTgBtn = document.getElementById('msgrTgBtn');
    const msgrHint = document.getElementById('msgrHint');
    const toastEl = document.getElementById('tableToast');
    const toastTitleEl = document.getElementById('toastTitle');
    const toastReasonEl = document.getElementById('toastReason');
    const dtpModal = document.getElementById('dtpModal');
    const dtpDateList = document.getElementById('dtpDateList');
    const dtpTimeList = document.getElementById('dtpTimeList');
    const dtpOk = document.getElementById('dtpOk');

    let last = null;
    let freeNums = new Set();
    let lastKey = '';
    let selectedTableNum = '';
    let isLoading = false;
    let capConfirmResolve = null;
    let toastTimer = null;
    let toastHideTimer = null;
    let reqGuestsHintTimer = null;
    let dtpDates = [];
    let dtpTimes = [];
    let dtpSelDate = null;
    let dtpSelTime = null;
    let skipNextResDateAutoLoad = false;

    const pad2 = (n) => String(n).padStart(2, '0');
    const isoDate = (d) => d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    const timeToMin = (hhmm) => {
      const m = String(hhmm || '').match(/^(\d{2}):(\d{2})$/);
      if (!m) return 0;
      return (Number(m[1]) * 60) + Number(m[2]);
    };

    const getMinSelectableSlot = () => {
      const now = new Date();
      const base = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours(), now.getMinutes(), 0, 0);
      const m = base.getMinutes();
      const add = (30 - (m % 30)) % 30;
      base.setMinutes(m + add, 0, 0);

      const minToday = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 10, 0, 0, 0);
      const maxToday = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 21, 0, 0, 0);

      let slot = base;
      if (slot < minToday) slot = minToday;
      if (slot > maxToday) slot = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 10, 0, 0, 0);

      return { dateVal: isoDate(slot), timeVal: pad2(slot.getHours()) + ':' + pad2(slot.getMinutes()) };
    };

    const clampToMinSlot = (dateVal, timeVal) => {
      const minSlot = getMinSelectableSlot();
      if (!dateVal) return minSlot;
      if (dateVal < minSlot.dateVal) return minSlot;
      if (dateVal === minSlot.dateVal && timeToMin(timeVal) < timeToMin(minSlot.timeVal)) return minSlot;
      return { dateVal, timeVal };
    };

    const fmtCashDate = (dtLocal) => {
      const raw = String(dtLocal || '').trim();
      const m = raw.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
      if (!m) return 'Выбрать дату';
      const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), Number(m[4]), Number(m[5]), 0);
      const datePart = new Intl.DateTimeFormat('ru-RU', { weekday: 'short', day: '2-digit', month: 'short' }).format(d);
      return datePart + ' · ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    };

    const setDtpModal = (on) => {
      if (!dtpModal) return;
      if (on) {
        dtpModal.classList.add('on');
        dtpModal.setAttribute('aria-hidden', 'false');
      } else {
        dtpModal.classList.remove('on');
        dtpModal.setAttribute('aria-hidden', 'true');
      }
    };

    const wheelIndex = (el, count) => {
      if (!el) return 0;
      const idx = Math.round(el.scrollTop / 40);
      return Math.max(0, Math.min((count || 1) - 1, idx));
    };

    const updateWheelActive = (listEl, idx) => {
      if (!listEl) return;
      Array.from(listEl.children).forEach((c, i) => {
        if (!(c instanceof HTMLElement)) return;
        if (i === idx) c.classList.add('active');
        else c.classList.remove('active');
      });
    };

    const setWheelTo = (listEl, idx) => {
      if (!listEl) return;
      listEl.scrollTop = Math.max(0, idx) * 40;
      updateWheelActive(listEl, idx);
    };

    const ensureDtpData = () => {
      if (!dtpDateList || !dtpTimeList) return;
      if (!dtpDates.length) {
        const now = new Date();
        const base = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0, 0);
        const days = 28;
        dtpDates = [];
        for (let i = 0; i < days; i++) {
          const d = new Date(base.getTime() + (i * 86400000));
          dtpDates.push({ value: isoDate(d), date: d });
        }
        dtpDateList.innerHTML = '';
        dtpDates.forEach(({ value, date }) => {
          const label = new Intl.DateTimeFormat('ru-RU', { weekday: 'short', day: '2-digit', month: 'short' }).format(date);
          const it = document.createElement('div');
          it.className = 'wheel-item';
          it.dataset.value = value;
          it.textContent = label;
          dtpDateList.appendChild(it);
        });
      }
      if (!dtpTimes.length) {
        dtpTimes = [];
        for (let h = 10; h <= 21; h++) {
          for (let m = 0; m < 60; m += 30) {
            if (h === 21 && m > 0) continue;
            dtpTimes.push({ value: pad2(h) + ':' + pad2(m) });
          }
        }
        dtpTimeList.innerHTML = '';
        dtpTimes.forEach(({ value }) => {
          const it = document.createElement('div');
          it.className = 'wheel-item';
          it.dataset.value = value;
          it.textContent = value;
          dtpTimeList.appendChild(it);
        });
      }
    };

    const syncDtpSelectionFromInput = () => {
      ensureDtpData();
      const raw = resDate ? String(resDate.value || '').trim() : '';
      const m = raw.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
      const fallback = getMinSelectableSlot();
      const picked = clampToMinSlot(m ? m[1] : fallback.dateVal, m ? m[2] : fallback.timeVal);
      dtpSelDate = picked.dateVal;
      const dIdx = Math.max(0, dtpDates.findIndex((x) => x.value === picked.dateVal));
      const tFoundIdx = dtpTimes.findIndex((x) => x.value === picked.timeVal);
      const tIdx = Math.max(0, tFoundIdx);
      dtpSelTime = tFoundIdx >= 0 ? picked.timeVal : (dtpTimes[0] ? dtpTimes[0].value : '10:00');
      setWheelTo(dtpDateList, dIdx);
      setWheelTo(dtpTimeList, tIdx);
    };

    const applyDtpToInput = () => {
      if (!resDate) return;
      const fallback = getMinSelectableSlot();
      const picked = clampToMinSlot(dtpSelDate || fallback.dateVal, dtpSelTime || fallback.timeVal);
      resDate.value = picked.dateVal + 'T' + picked.timeVal;
      if (resDateBtn) resDateBtn.textContent = fmtCashDate(resDate.value);
      resDate.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const clampDtpTimeForSelectedDate = () => {
      ensureDtpData();
      const minSlot = getMinSelectableSlot();
      if (!dtpSelDate) return;
      if (dtpSelDate === minSlot.dateVal) {
        const cur = dtpSelTime || (dtpTimes[0] ? dtpTimes[0].value : minSlot.timeVal);
        if (timeToMin(cur) < timeToMin(minSlot.timeVal)) {
          dtpSelTime = minSlot.timeVal;
          const idx = Math.max(0, dtpTimes.findIndex((x) => x.value === dtpSelTime));
          setWheelTo(dtpTimeList, idx);
        }
      }
    };

    if (dtpDateList) {
      let t = null;
      dtpDateList.addEventListener('scroll', () => {
        if (t) clearTimeout(t);
        t = setTimeout(() => {
          const idx = wheelIndex(dtpDateList, dtpDates.length);
          updateWheelActive(dtpDateList, idx);
          dtpSelDate = dtpDates[idx] ? dtpDates[idx].value : dtpSelDate;
          clampDtpTimeForSelectedDate();
        }, 80);
      });
    }
    if (dtpTimeList) {
      let t = null;
      dtpTimeList.addEventListener('scroll', () => {
        if (t) clearTimeout(t);
        t = setTimeout(() => {
          const idx = wheelIndex(dtpTimeList, dtpTimes.length);
          updateWheelActive(dtpTimeList, idx);
          dtpSelTime = dtpTimes[idx] ? dtpTimes[idx].value : dtpSelTime;
          clampDtpTimeForSelectedDate();
        }, 80);
      });
    }
    if (dtpOk) dtpOk.addEventListener('click', () => {
      skipNextResDateAutoLoad = true;
      applyDtpToInput();
      setDtpModal(false);
      loadFree(false).catch((e) => setOutput('Ошибка: ' + String(e && e.message ? e.message : e)));
    });
    document.querySelectorAll('[data-dtp-close]').forEach((x) => x.addEventListener('click', () => setDtpModal(false)));
    if (resDateBtn) {
      resDateBtn.addEventListener('click', () => {
        syncDtpSelectionFromInput();
        setDtpModal(true);
      });
    }

    const setModal = (el, on) => {
      if (!el) return;
      if (on) {
        el.classList.add('on');
        el.setAttribute('aria-hidden', 'false');
      } else {
        el.classList.remove('on');
        el.setAttribute('aria-hidden', 'true');
      }
    };

    document.querySelectorAll('[data-modal-close]').forEach((x) => {
      x.addEventListener('click', () => {
        const id = String(x.getAttribute('data-modal-close') || '');
        if (!id) return;
        const el = document.getElementById(id);
        setModal(el, false);
        if (id === 'capModal' && typeof capConfirmResolve === 'function') {
          capConfirmResolve(false);
          capConfirmResolve = null;
        }
      });
    });

    const confirmCapacity = (maxCap, guests) => new Promise((resolve) => {
      capConfirmResolve = resolve;
      if (capModalText) capModalText.textContent = `Вы хотите забронировать столик для ${maxCap} для ${guests} гостей?`;
      setModal(capModal, true);
    });

    if (capModalYes) {
      capModalYes.addEventListener('click', () => {
        setModal(capModal, false);
        if (typeof capConfirmResolve === 'function') capConfirmResolve(true);
        capConfirmResolve = null;
      });
    }
    if (capModalNo) {
      capModalNo.addEventListener('click', () => {
        setModal(capModal, false);
        if (typeof capConfirmResolve === 'function') capConfirmResolve(false);
        capConfirmResolve = null;
      });
    }

    let pendingBooking = null;
    let messengerLinked = { telegram: false, whatsapp: false, zalo: false };
    let linkedTg = null;
    let submitBusy = false;
    let submitPrevText = '';
    const syncSubmitState = () => {
      if (!reqSubmit) return;
      const linked = !!(messengerLinked.telegram || messengerLinked.whatsapp || messengerLinked.zalo);
      if (linked) reqSubmit.classList.remove('is-disabled');
      else reqSubmit.classList.add('is-disabled');
      reqSubmit.setAttribute('aria-disabled', linked ? 'false' : 'true');
      reqSubmit.disabled = !!submitBusy;
    };
    const openRequestForm = ({ tableNum, guests, start, name, phone, keepFields }) => {
      pendingBooking = { tableNum: String(tableNum || ''), guests: Number(guests || 0), start: String(start || '') };
      if (reqModalTable) reqModalTable.textContent = String(tableNum || '');
      if (reqGuests) reqGuests.value = String(guests);
      if (reqStart) reqStart.value = String(start);
      if (!keepFields) {
        if (reqName) reqName.value = '';
        if (reqPhone) reqPhone.value = '';
        messengerLinked = { telegram: false, whatsapp: false, zalo: false };
        linkedTg = null;
      } else {
        if (reqName) reqName.value = String(name || '');
        if (reqPhone) reqPhone.value = String(phone || '');
      }
      if (reqHint) { reqHint.hidden = true; reqHint.textContent = ''; reqHint.classList.remove('warn'); }
      syncSubmitState();
      if (!(messengerLinked.telegram || messengerLinked.whatsapp || messengerLinked.zalo)) setMsgrHint('Для отправки привяжи Telegram.');
      setModal(reqModal, true);
      if (reqName) reqName.focus();
    };

    let msgrBusy = false;
    const setMsgrHint = (msg) => {
      if (!msgrHint) return;
      const t = String(msg || '').trim();
      if (!t) { msgrHint.hidden = true; msgrHint.textContent = ''; return; }
      msgrHint.hidden = false;
      msgrHint.textContent = t;
    };

    const startTelegramFlow = async () => {
      if (!msgrTgBtn || msgrBusy) return;
      if (!pendingBooking) { setMsgrHint('Сначала выбери столик.'); return; }
      const tableNum = String(pendingBooking.tableNum || '');
      const guests = reqGuests ? Number(reqGuests.value || pendingBooking.guests || 0) : Number(pendingBooking.guests || 0);
      const start = reqStart ? String(reqStart.value || pendingBooking.start || '').trim() : String(pendingBooking.start || '').trim();
      const name = reqName ? String(reqName.value || '').trim() : '';
      const phone = reqPhone ? String(reqPhone.value || '').trim() : '';
      const resDt = resDate ? String(resDate.value || '').trim() : '';
      const scrollY = Math.max(0, Math.floor(window.scrollY || 0));

      msgrBusy = true;
      msgrTgBtn.disabled = true;
      setMsgrHint('Открываю Telegram…');
      try {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'tg_state_create');
        const res = await fetch(url.toString(), {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ table_num: tableNum, guests, start, name, phone, res_date: resDt, scroll_y: scrollY }),
        });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        const botUrl = String(j.bot_url || '').trim();
        if (!botUrl) throw new Error('Нет ссылки на бота');
        setMsgrHint('В Telegram нажми “Вернуться на сайт”.');
        window.location.href = botUrl;
      } catch (e) {
        setMsgrHint(String(e && e.message ? e.message : e));
      } finally {
        msgrBusy = false;
        msgrTgBtn.disabled = false;
      }
    };

    if (msgrTgBtn) msgrTgBtn.addEventListener('click', () => { startTelegramFlow().catch(() => null); });

    const updateReqGuestsHint = async () => {
      if (!reqHint) return;
      const tableNum = pendingBooking ? String(pendingBooking.tableNum || '') : '';
      const guests = reqGuests ? Number(reqGuests.value || 0) : 0;
      if (!tableNum || !isFinite(guests) || guests <= 0) {
        reqHint.hidden = true;
        reqHint.textContent = '';
        reqHint.classList.remove('warn');
        return;
      }
      try {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'cap_check');
        url.searchParams.set('table_num', tableNum);
        url.searchParams.set('guests', String(Math.floor(guests)));
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
        if (j.status === 'warn' && j.message) {
          reqHint.innerHTML = '<span class="hint-text">' + esc(String(j.message)) + '</span>';
          reqHint.classList.add('warn');
          reqHint.hidden = false;
        } else {
          reqHint.hidden = true;
          reqHint.textContent = '';
          reqHint.classList.remove('warn');
        }
      } catch (_) {
        reqHint.hidden = true;
        reqHint.textContent = '';
        reqHint.classList.remove('warn');
      }
    };

    if (reqGuests) {
      reqGuests.addEventListener('input', () => {
        if (pendingBooking) pendingBooking.guests = Number(reqGuests.value || 0) || pendingBooking.guests;
        if (reqGuestsHintTimer) clearTimeout(reqGuestsHintTimer);
        reqGuestsHintTimer = setTimeout(() => { updateReqGuestsHint().catch(() => null); }, 180);
      });
      reqGuests.addEventListener('change', () => { updateReqGuestsHint().catch(() => null); });
    }

    if (reqForm) {
      reqForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (submitBusy) return;
        const name = reqName ? String(reqName.value || '').trim() : '';
        const phone = reqPhone ? String(reqPhone.value || '').trim() : '';
        const guests = reqGuests ? Number(reqGuests.value || 0) : 0;
        const start = reqStart ? String(reqStart.value || '').trim() : '';
        const tableNum = pendingBooking ? String(pendingBooking.tableNum || '') : '';
        const missing = [];
        if (!tableNum) missing.push('выбери стол');
        if (!start) missing.push('время старта');
        if (!isFinite(guests) || guests <= 0) missing.push('кол-во гостей');
        if (!name) missing.push('имя');
        if (!phone) missing.push('телефон');
        if (!(messengerLinked.telegram || messengerLinked.whatsapp || messengerLinked.zalo)) missing.push('Telegram (привязать)');
        if (missing.length) {
          const msg = 'Не хватает: ' + missing.join(', ');
          setOutput({ ok: false, error: msg });
          setMsgrHint(msg);
          syncSubmitState();
          return;
        }

        submitBusy = true;
        if (reqSubmit) {
          submitPrevText = String(reqSubmit.textContent || '');
          reqSubmit.textContent = 'Отправляю…';
          reqSubmit.disabled = true;
        }
        try {
          const url = new URL(location.href);
          url.searchParams.set('ajax', 'submit_booking');
          const res = await fetch(url.toString(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ table_num: tableNum, guests, start, name, phone, tg: linkedTg }),
          });
          const j = await res.json().catch(() => null);
          if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
          setModal(reqModal, false);
          setOutput('Спасибо, мы с вами свяжемся в ближайшее время.\n\nДата: ' + String(start).slice(0, 10) + '\nВремя: ' + String(start).slice(11, 16) + '\nСтол: ' + tableNum + '\nГостей: ' + String(guests) + '\nИмя: ' + name + '\nТелефон: ' + phone);
        } catch (err) {
          setOutput({ ok: false, error: String(err && err.message ? err.message : err) });
        } finally {
          submitBusy = false;
          if (reqSubmit) {
            if (submitPrevText) reqSubmit.textContent = submitPrevText;
            reqSubmit.disabled = false;
          }
          syncSubmitState();
        }
      });
    }

    const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const fmtJson = (x) => {
      try { return JSON.stringify(x, null, 2); } catch (_) { return String(x); }
    };

    const parseSel = (dtRaw) => {
      const raw = String(dtRaw || '').trim();
      const m = raw.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/);
      if (!m) return null;
      const hh = Number(m[2]);
      const mm = Number(m[3]);
      if (!isFinite(hh) || !isFinite(mm)) return null;
      const day = m[1];
      const selMin = (hh * 60) + mm;
      const now = new Date();
      const todayStr = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
      return { day, selMin, isToday: day === todayStr };
    };

    const fmtMin = (m) => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');

    const positionToast = (target) => {
      if (!toastEl || !target) return;
      const r = target.getBoundingClientRect();
      const x = Math.round(r.left + (r.width / 2));
      const y = Math.round(r.top);
      toastEl.style.left = String(x) + 'px';
      toastEl.style.top = String(y) + 'px';
    };

    const hideToast = () => {
      if (!toastEl) return;
      toastEl.classList.remove('on');
      if (toastTimer) { clearTimeout(toastTimer); toastTimer = null; }
    };

    const showToast = (target, reason, detail) => {
      if (!toastEl || !toastTitleEl || !toastReasonEl) return;
      if (toastHideTimer) { clearTimeout(toastHideTimer); toastHideTimer = null; }
      positionToast(target);
      toastTitleEl.textContent = 'Этот столик не доступен';
      toastReasonEl.innerHTML = 'Причина: <b>' + esc(reason) + '</b>' + (detail ? (' · ' + esc(detail)) : '');
      toastEl.classList.add('on');
      if (toastTimer) clearTimeout(toastTimer);
      toastTimer = setTimeout(hideToast, 2200);
    };

    const getUnavailableReason = (tableNum, current) => {
      const tEl = tables.find((x) => String(x.dataset.table || '') === String(tableNum));
      if (!tEl) return null;
      if (tEl.classList.contains('disabled')) return { reason: 'отключено в настройках', detail: '' };
      if (!last || !current) return null;
      const ps = parseSel(current.dtRaw);
      const ranges = Array.isArray(lastReservationsByTable[String(tableNum)]) ? lastReservationsByTable[String(tableNum)] : [];
      if (ps && ranges.length) {
        const selEnd = ps.selMin + 120;
        const overlaps = ranges.some(([s, e]) => s < selEnd && e > ps.selMin);
        if (overlaps) {
          const txt = ranges.slice(0, 2).map(([s, e]) => fmtMin(s) + '-' + fmtMin(e)).join(' · ');
          return { reason: 'там есть бронь', detail: txt };
        }
      }
      if (!freeNums.has(String(tableNum))) {
        if (ps && ps.isToday) return { reason: 'гости сейчас сидят', detail: '' };
        return { reason: 'недоступен на это время', detail: '' };
      }
      return null;
    };

    const setOutput = (obj) => {
      if (!resultText) return;
      if (typeof obj === 'string') {
        resultText.value = obj;
        return;
      }
      resultText.value = fmtJson(obj);
    };

    const formatReservationsOnlyText = (items, meta) => {
      const list = Array.isArray(items) ? items : [];
      const lines = [];
      lines.push('Текущие брони (incomingOrders.getReservations)');
      if (meta && typeof meta === 'object') {
        if (meta.date_from || meta.date_to) {
          lines.push(`Интервал: ${String(meta.date_from || '')} — ${String(meta.date_to || '')}`);
        }
      }
      lines.push('');
      lines.push('Формат: ID стола | Имя стола | Статус | Имя | Старт брони | Конец брони | Кол-во человек');
      if (!list.length) {
        lines.push('—');
        return lines.join('\n');
      }
      list.slice(0, 120).forEach((it) => {
        const tableId = String(it.table_id ?? '—');
        const tableTitle = String(it.table_title ?? '—');
        const status = String(it.status ?? '—');
        const name = String(it.guest_name ?? '—');
        const start = String(it.date_start ?? '—');
        const end = String(it.date_end ?? '—');
        const guests = String(it.guests_count ?? '—');
        lines.push(`${tableId} | ${tableTitle} | ${status} | ${name} | ${start} | ${end} | ${guests}`);
      });
      if (list.length > 120) lines.push(`… ещё ${list.length - 120}`);
      return lines.join('\n');
    };

    const setStatus = (tableNum) => {
      if (selectedTableEl) selectedTableEl.textContent = tableNum ? String(tableNum) : '—';
      if (!tableNum) {
        if (statusLine) statusLine.textContent = '—';
        return;
      }
      if (isLoading) {
        if (statusLine) statusLine.textContent = 'Проверяю…';
        return;
      }
      if (!last) {
        if (statusLine) statusLine.textContent = 'Нажми "Проверить свободные столы"';
        return;
      }
      const isFree = freeNums.has(String(tableNum));
      if (statusLine) statusLine.textContent = isFree ? 'Свободен' : 'Занят';
    };

    const applyAvailabilityStyles = () => {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        t.classList.remove('free', 'busy');
        if (!last) return;
        if (freeNums.has(n)) t.classList.add('free');
        else t.classList.add('busy');
      });
    };

    const getCurrentRequest = () => {
      const dtRaw = resDate ? String(resDate.value || '').trim() : '';
      if (!dtRaw) return null;
      const dt = dtRaw.replace('T', ' ') + ':00';
      return { dt, guests: 1, dtRaw, durationSec: 7200, durationHours: 2 };
    };

    const invalidateLast = () => {
      last = null;
      freeNums = new Set();
      lastKey = '';
      applyAvailabilityStyles();
      clearReservationsOnTables();
      renderSelectedTable();
    };

    const renderSelectedTable = () => {
      setStatus(selectedTableNum);
      if (!selectedTableNum) return;
      if (!last) {
        setOutput('Нажми "Проверить свободные столы"');
        return;
      }
      setOutput(formatReservationsOnlyText(last.reservations_items, last.reservations_request));
    };
  
    tables.forEach(table => {
      table.addEventListener('mouseenter', () => {
        const id = String(table.dataset.table || '');
        const current = getCurrentRequest();
        const un = getUnavailableReason(id, current);
        if (un) showToast(table, un.reason, un.detail);
      });
      table.addEventListener('mouseleave', () => {
        if (toastHideTimer) clearTimeout(toastHideTimer);
        toastHideTimer = setTimeout(hideToast, 180);
      });

      table.addEventListener('click', async () => {
        const id = String(table.dataset.table || '');
        const current = getCurrentRequest();
        if (!current) {
          if (!resDate || !String(resDate.value || '').trim()) {
            setStatus(id);
            setOutput({ ok: false, error: 'Выбери дату и время' });
            return;
          }
          setOutput({ ok: false, error: 'Выбери дату и время' });
          return;
        }

        const key = current.dt + '|' + String(current.guests);
        if ((!last || lastKey !== key) && !isLoading) {
          try {
            await loadFree(true);
          } catch (e) {
            setOutput({ ok: false, error: String(e && e.message ? e.message : e) });
            return;
          }
        }

        const un = getUnavailableReason(id, current);
        if (un) {
          selectedTableNum = '';
          showToast(table, un.reason, un.detail);
          return;
        }

        const cap = tableCapsByNum && typeof tableCapsByNum === 'object' && tableCapsByNum[id] != null ? Number(tableCapsByNum[id]) : null;
        if (cap != null && isFinite(cap) && current.guests > cap) {
          const ok = await confirmCapacity(Math.max(1, Math.floor(cap)), current.guests);
          if (!ok) {
            selectedTableNum = '';
            setOutput('Исправь кол-во гостей и выбери столик снова.');
            return;
          }
        }

        selectedTableNum = id;
        openRequestForm({ tableNum: id, guests: current.guests, start: current.dtRaw });
      });
    });

    const initDate = () => {
      if (!resDate) return;
      resDate.value = defaultResDateLocal || '';
      const minSlot = getMinSelectableSlot();
      resDate.min = minSlot.dateVal + 'T' + minSlot.timeVal;
      if (resDateBtn) resDateBtn.textContent = fmtCashDate(resDate.value);
      setBusyLabel(String(resDate.value || '').slice(0, 10));
      clearReservationsOnTables();
    };

    const syncSteps = () => {
      const hasDate = !!(resDate && String(resDate.value || '').trim());
      if (stepGuests) stepGuests.hidden = !hasDate;
      if (stepCheck) stepCheck.hidden = !hasDate;
      if (!hasDate) invalidateLast();
    };

    const loadFree = async (silent) => {
      if (isLoading) return;
      const current = getCurrentRequest();
      if (!current) {
        if (!resDate || !String(resDate.value || '').trim()) setOutput({ ok: false, error: 'Выбери дату и время' });
        else setOutput({ ok: false, error: 'Выбери дату и время' });
        return;
      }

      const dt = current.dt;
      const guests = current.guests;
      const key = dt + '|' + String(guests);
      const dateStr = String(dt).slice(0, 10);

      isLoading = true;
      if (statusLine) statusLine.textContent = 'Проверяю…';
      if (checkBtn) checkBtn.disabled = true;
      setBusyLabel(dateStr);
      setBusyLoader(true);

      const url = new URL(location.href);
      url.searchParams.set('ajax', 'free_tables');
      url.searchParams.set('date_reservation', dt);
      url.searchParams.set('duration', String(current.durationSec || 7200));
      url.searchParams.set('spot_id', '1');
      url.searchParams.set('guests_count', String(guests));

      const loadReservations = async () => {
        const rUrl = new URL(location.href);
        rUrl.searchParams.set('ajax', 'reservations');
        rUrl.searchParams.set('date_reservation', dt);
        rUrl.searchParams.set('duration', String(current.durationSec || 7200));
        rUrl.searchParams.set('spot_id', '1');
        const rRes = await fetch(rUrl.toString(), { headers: { 'Accept': 'application/json' } });
        const rJ = await rRes.json().catch(() => null);
        if (!rRes.ok || !rJ || !rJ.ok) return null;
        return rJ;
      };

      try {
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) {
          last = null;
          freeNums = new Set();
          lastKey = '';
          applyAvailabilityStyles();
          setOutput(j && typeof j === 'object' ? fmtJson(j) : 'Ошибка запроса');
          renderSelectedTable();
          return;
        }

        last = j;
        lastKey = key;
        freeNums = new Set(Array.isArray(j.free_table_nums) ? j.free_table_nums.map(String) : []);
        applyAvailabilityStyles();
        const r = await loadReservations().catch(() => null);
        if (r) {
          last.reservations_request = r.request;
          last.reservations_items = r.reservations_items;
        } else {
          last.reservations_request = null;
          last.reservations_items = [];
        }
        clearReservationsOnTables();
        applyReservationsItemsToTables(last.reservations_items, dateStr, dt);
        if (!silent) setOutput(formatReservationsOnlyText(last.reservations_items, last.reservations_request));
        renderSelectedTable();
      } finally {
        isLoading = false;
        if (checkBtn) checkBtn.disabled = false;
        setStatus(selectedTableNum);
        setBusyLoader(false);
      }
    };

    if (checkBtn) {
      checkBtn.addEventListener('click', () => {
        loadFree(false).catch((e) => setOutput('Ошибка: ' + String(e && e.message ? e.message : e)));
      });
    }

    initDate();
    const restoreFromTgState = async () => {
      const params = new URLSearchParams(location.search);
      const code = String(params.get('tg_state') || '').trim();
      if (!code) return;
      try {
        const url = new URL(location.href);
        url.searchParams.set('ajax', 'tg_state_get');
        url.searchParams.set('code', code);
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok || !j.payload) throw new Error((j && j.error) ? j.error : 'Ошибка');
        const p = j.payload || {};
        const resDt = String(p.res_date || '').trim();
        if (resDate && resDt && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(resDt)) {
          skipNextResDateAutoLoad = true;
          resDate.value = resDt;
          if (resDateBtn) resDateBtn.textContent = fmtCashDate(resDate.value);
          setBusyLabel(String(resDate.value || '').slice(0, 10));
          invalidateLast();
        }
        const tableNum = String(p.table_num || '').trim();
        const guests = Number(p.guests || 0) || 0;
        const start = String(p.start || '').trim();
        const name = String(p.name || '');
        const phone = String(p.phone || '');
        const tg = j.tg && typeof j.tg === 'object' ? j.tg : null;
        if (tableNum && guests > 0 && start) {
          selectedTableNum = tableNum;
          messengerLinked.telegram = true;
          linkedTg = tg ? { user_id: Number(tg.user_id || 0) || 0, username: String(tg.username || ''), name: String(tg.name || '') } : null;
          openRequestForm({ tableNum, guests, start, name, phone, keepFields: true });
          if (linkedTg && linkedTg.username) setMsgrHint('Telegram привязан ✅ @' + String(linkedTg.username).replace(/^@+/, ''));
          else setMsgrHint('Telegram привязан ✅');
          syncSubmitState();
          updateReqGuestsHint().catch(() => null);
        }
        const scrollY = Math.max(0, Math.floor(Number(p.scroll_y || 0) || 0));
        if (scrollY > 0) setTimeout(() => { window.scrollTo(0, scrollY); }, 60);
      } catch (_) {
      } finally {
        const next = new URL(location.href);
        next.searchParams.delete('tg_state');
        history.replaceState(null, '', next.toString());
      }
    };
    restoreFromTgState().catch(() => null);
    syncSteps();
    if (resDate) {
      resDate.addEventListener('input', () => { syncSteps(); invalidateLast(); setBusyLabel(String(resDate.value || '').slice(0, 10)); });
      resDate.addEventListener('change', () => {
        syncSteps();
        invalidateLast();
        setBusyLabel(String(resDate.value || '').slice(0, 10));
        if (skipNextResDateAutoLoad) { skipNextResDateAutoLoad = false; return; }
        loadFree(true).catch(() => null);
      });
    }
    if (resDate && String(resDate.value || '').trim()) {
      loadFree(true).catch(() => null);
    }
    setOutput('Выбери дату и нажми "Проверить столики". Потом кликай по столам.');
  </script>
</body>
</html>
