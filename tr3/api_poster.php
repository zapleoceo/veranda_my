<?php
declare(strict_types=1);

function tr3_api_free_tables(array $ctx): void {
  api_json_headers(true);

  $posterToken = trim((string)($ctx['posterToken'] ?? ''));
  if ($posterToken === '') api_error(500, 'POSTER_API_TOKEN не задан');

  $dateReservation = trim((string)($_GET['date_reservation'] ?? ''));
  $duration = (int)($_GET['duration'] ?? 0);
  $guests = (int)($_GET['guests_count'] ?? 0);
  $spotId = (int)($_GET['spot_id'] ?? 1);
  $hallId = 2;
  $allowed = is_array($ctx['allowedSchemeNums'] ?? null) ? $ctx['allowedSchemeNums'] : null;

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
      } catch (\Throwable $e) {}
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
      'free_table_nums' => array_values(array_keys($nums)),
      'occupied_now_nums' => $occupiedNowNums,
      'busy_reasons' => $busyReasons,
      'free_tables' => $filtered,
      'raw' => null,
    ], 200);
  } catch (\Throwable $e) {
    api_error(500, 'Poster request failed');
  }
}

function tr3_api_reservations(array $ctx): void {
  api_json_headers(true);

  $posterToken = trim((string)($ctx['posterToken'] ?? ''));
  if ($posterToken === '') api_error(500, 'POSTER_API_TOKEN не задан');

  $dateReservation = trim((string)($_GET['date_reservation'] ?? ''));
  $spotId = (int)($_GET['spot_id'] ?? 1);
  $hallId = 2;
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
    api_error(500, 'Poster request failed');
  }
}

function tr3_api_cap_check(array $ctx): void {
  api_json_headers(true);

  $tableNum = trim((string)($_GET['table_num'] ?? ''));
  $guests = (int)($_GET['guests'] ?? 0);
  if ($tableNum === '') api_error(400, 'Некорректный номер стола');
  if ($guests <= 0 || $guests > 99) api_error(400, 'Некорректное кол-во гостей');

  $caps = is_array($ctx['tableCapsByNum'] ?? null) ? $ctx['tableCapsByNum'] : [];
  $cap = isset($caps[$tableNum]) ? (int)$caps[$tableNum] : null;
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

