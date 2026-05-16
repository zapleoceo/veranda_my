<?php
declare(strict_types=1);

function _tr3_poster_catch(\Throwable $e): never {
  if ($e instanceof \RuntimeException && $e->getMessage() === '_tr3_api_done') throw $e;
  $dbg = trim((string)($_ENV['DEBUG'] ?? $_ENV['TR3_DEBUG'] ?? ''));
  $msg = ($dbg === '1' || (string)($_GET['_dbg'] ?? '') === '1') ? $e->getMessage() : 'Poster request failed';
  api_error(500, $msg);
}

function tr3_api_free_tables(array $ctx): void {
  api_json_headers(true);

  $posterToken = trim((string)($ctx['posterToken'] ?? ''));
  if ($posterToken === '') api_error(500, 'POSTER_API_TOKEN не задан');

  $dateReservation = trim((string)($_GET['date_reservation'] ?? ''));
  $duration = (int)($_GET['duration'] ?? 0);
  $guests = (int)($_GET['guests_count'] ?? 0);
  $spotId = (int)($_GET['spot_id'] ?? 1);
  $hallId = (int)($_GET['hall_id'] ?? 2);
  if ($hallId <= 0) $hallId = 2;
  $settingsByHall = is_array($ctx['tableSettingsByHall'] ?? null) ? $ctx['tableSettingsByHall'] : [];
  $settingsMap = isset($settingsByHall[(string)$hallId]) && is_array($settingsByHall[(string)$hallId]) ? $settingsByHall[(string)$hallId] : [];

  $displayTzName = (string)($ctx['displayTzName'] ?? 'Asia/Ho_Chi_Minh');
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
  if ($dtDisplay === false || $dateReservation === '') api_error(400, 'Некорректная дата');
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
    $bookableTableIds = [];
    foreach ($tablesResp as $trRow) {
      if (!is_array($trRow)) continue;
      $id = (int)($trRow['table_id'] ?? 0);
      if ($id <= 0) continue;
      $cfg = isset($settingsMap[(string)$id]) && is_array($settingsMap[(string)$id]) ? $settingsMap[(string)$id] : null;
      if (!$cfg) continue;
      $show = (int)($cfg['show_on_canvas'] ?? 1);
      $book = (int)($cfg['bookable'] ?? 0);
      if ($show !== 1 || $book !== 1) continue;
      $bookableTableIds[(string)$id] = true;
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
      } catch (\Throwable $e) {}
    }

    $occupiedNowNums = [];
    if ($busyTableIds) {
      foreach (array_keys($busyTableIds) as $tId) {
        $k = (string)$tId;
        if (isset($bookableTableIds[$k])) $occupiedNowNums[$k] = true;
      }
      $occupiedNowNums = array_values(array_keys($occupiedNowNums));
      sort($occupiedNowNums, SORT_NUMERIC);
    } else {
      $occupiedNowNums = [];
    }

    $freeIds = [];
    foreach (array_keys($bookableTableIds) as $id) {
      if ($isToday && in_array((string)$id, $occupiedNowNums, true)) continue;
      $freeIds[(string)$id] = true;
    }

    $busyReasons = [];
    if ($isToday) {
      foreach ($occupiedNowNums as $n) {
        $s = (string)$n;
        if (!isset($busyReasons[$s])) $busyReasons[$s] = [];
        if (!in_array('occupied_now', $busyReasons[$s], true)) $busyReasons[$s][] = 'occupied_now';
      }
    }

    api_send_json([
      'ok' => true,
      'request' => [
        'date_reservation' => $dtDisplay->format('Y-m-d H:i:s'),
        'date_reservation_api' => $dtDisplay->format('Y-m-d H:i:s'),
        'duration' => $duration,
        'spot_id' => $spotId,
        'guests_count' => $guests,
        'hall_id' => $hallId,
      ],
      'free_table_ids' => array_values(array_keys($freeIds)),
      'occupied_now_table_ids' => $occupiedNowNums,
      'busy_reasons' => $busyReasons,
      'free_tables' => null,
      'raw' => null,
    ], 200);
  } catch (\Throwable $e) {
    _tr3_poster_catch($e);
  }
}

function tr3_api_reservations(array $ctx): void {
  api_json_headers(true);

  $posterToken = trim((string)($ctx['posterToken'] ?? ''));
  if ($posterToken === '') api_error(500, 'POSTER_API_TOKEN не задан');

  $dateReservation = trim((string)($_GET['date_reservation'] ?? ''));
  $spotId = (int)($_GET['spot_id'] ?? 1);
  $hallId = (int)($_GET['hall_id'] ?? 2);
  if ($hallId <= 0) $hallId = 2;
  $displayTzName = (string)($ctx['displayTzName'] ?? 'Asia/Ho_Chi_Minh');
  $displayTz = new DateTimeZone($displayTzName);
  $dtDisplay = api_parse_datetime_local($dateReservation, $displayTz);
  if (!$dtDisplay || $dateReservation === '') api_error(400, 'Некорректная дата');
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
      api_send_json([
        'ok' => true,
        'request' => [
          'method' => 'incomingOrders.getReservations',
          'params' => $params,
          'display_timezone' => $displayTzName,
          'api_timezone' => (string)($ctx['apiTzName'] ?? $displayTzName),
        ],
        'count_raw' => is_array($resp) ? count($resp) : 0,
        'poster_response' => $resp,
      ], 200);
    }

    $respAll = $api->request('incomingOrders.getReservations', [
      'timezone' => 'client',
    ], 'GET');

    $settingsByHall = is_array($ctx['tableSettingsByHall'] ?? null) ? $ctx['tableSettingsByHall'] : [];
    $settingsMap = isset($settingsByHall[(string)$hallId]) && is_array($settingsByHall[(string)$hallId]) ? $settingsByHall[(string)$hallId] : [];

    $tableNameById = [];
    foreach ($settingsMap as $pid => $cfg) {
      if (!is_array($cfg)) continue;
      $pidInt = (int)$pid;
      if ($pidInt <= 0) continue;
      $show = (int)($cfg['show_on_canvas'] ?? 1);
      if ($show !== 1) continue;
      $label = trim((string)($cfg['display_name'] ?? ''));
      if ($label === '') {
        $scheme = $cfg['scheme_num'] ?? null;
        $label = ($scheme !== null && $scheme !== '') ? (string)$scheme : '';
      }
      if ($label === '') $label = '#' . (string)$pidInt;
      $tableNameById[(string)$pidInt] = $label;
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

    api_send_json([
      'ok' => true,
      'request' => [
        'date_from' => $dayStartDisplay->format('Y-m-d H:i:s'),
        'date_to' => $dayEndDisplay->format('Y-m-d H:i:s'),
        'date_from_api' => null,
        'date_to_api' => null,
        'spot_id' => $spotId,
        'hall_id' => $hallId,
        'display_timezone' => $displayTzName,
        'api_timezone' => (string)($ctx['apiTzName'] ?? $displayTzName),
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
    ], 200);
  } catch (\Throwable $e) {
    _tr3_poster_catch($e);
  }
}

function tr3_api_cap_check(array $ctx): void {
  api_json_headers(true);

  $posterTableId = (int)($_GET['poster_table_id'] ?? 0);
  $guests = (int)($_GET['guests'] ?? 0);
  $hallId = (int)($_GET['hall_id'] ?? 2);
  if ($hallId <= 0) $hallId = 2;
  if ($posterTableId <= 0) api_error(400, 'Некорректный номер стола');
  if ($guests <= 0 || $guests > 99) api_error(400, 'Некорректное кол-во гостей');

  $cap = null;
  $byHall = $ctx['tableSettingsByHall'] ?? null;
  if (is_array($byHall) && isset($byHall[(string)$hallId]) && is_array($byHall[(string)$hallId])) {
    $cfg = $byHall[(string)$hallId][(string)$posterTableId] ?? null;
    if (is_array($cfg)) $cap = isset($cfg['capacity']) ? (int)$cfg['capacity'] : null;
  }
  if ($cap !== null && $cap > 0 && $guests > $cap) {
    api_send_json([
      'ok' => true,
      'cap' => $cap,
      'status' => 'warn',
      'message' => tr('cap_warn'),
    ], 200);
  }

  api_send_json([
    'ok' => true,
    'cap' => $cap,
    'status' => 'ok',
    'message' => '',
  ], 200);
}

function tr3_api_hall_tables(array $ctx): void {
  api_json_headers(true);

  $posterToken = trim((string)($ctx['posterToken'] ?? ''));
  if ($posterToken === '') api_error(500, 'POSTER_API_TOKEN не задан');

  $spotId = (int)($_GET['spot_id'] ?? 1);
  if ($spotId <= 0) $spotId = 1;
  $hallId = (int)($_GET['hall_id'] ?? 0);
  if ($hallId <= 0) api_error(400, 'Некорректный hall_id');

  $api = new \App\Classes\PosterAPI($posterToken);
  try {
    $rows = $api->request('spots.getTableHallTables', [
      'spot_id' => $spotId,
      'hall_id' => $hallId,
      'without_deleted' => 1,
    ], 'GET');
    $rows = is_array($rows) ? $rows : [];
    $out = [];
    foreach ($rows as $r) {
      if (!is_array($r)) continue;
      $tid = (int)($r['table_id'] ?? 0);
      if ($tid <= 0) continue;
      $label = api_resolve_table_label($ctx, $hallId, $tid);
      $out[] = [
        'table_id' => $tid,
        'poster_table_id' => $tid,
        'table_num' => (string)($r['table_num'] ?? ''),
        'table_title' => (string)($r['table_title'] ?? ''),
        'table_label' => $label,
        'spot_id' => (int)($r['spot_id'] ?? $spotId),
        'hall_id' => (int)($r['hall_id'] ?? $hallId),
        'table_shape' => (string)($r['table_shape'] ?? ''),
        'table_x' => (float)($r['table_x'] ?? 0),
        'table_y' => (float)($r['table_y'] ?? 0),
        'table_width' => (float)($r['table_width'] ?? 0),
        'table_height' => (float)($r['table_height'] ?? 0),
      ];
    }
    api_send_json([
      'ok' => true,
      'spot_id' => $spotId,
      'hall_id' => $hallId,
      'tables' => $out,
    ], 200);
  } catch (\Throwable $e) {
    _tr3_poster_catch($e);
  }
}
