<?php
declare(strict_types=1);

function tr3_api_submit_booking(array $ctx): void {
  api_json_headers(true);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_error(405, 'Method not allowed');

  $payload = api_read_payload();

  $I18N = is_array($ctx['I18N'] ?? null) ? $ctx['I18N'] : [];
  $defaultLang = (string)($ctx['lang'] ?? 'ru');
  $langIn = strtolower(trim((string)($payload['lang'] ?? '')));
  $userLang = in_array($langIn, ['ru', 'en', 'vi'], true) ? $langIn : $defaultLang;
  $trFor = function (string $key) use ($I18N, $userLang): string {
    return isset($I18N[$userLang][$key]) ? (string)$I18N[$userLang][$key] : $key;
  };

  $tableNum = trim((string)($payload['table_num'] ?? ''));
  if ($tableNum === '') $tableNum = trim((string)($payload['table_num_manual'] ?? ''));
  $name = trim((string)($payload['name'] ?? ''));
  $phone = trim((string)($payload['phone'] ?? ''));
  $waPhone = trim((string)($payload['whatsapp_phone'] ?? ''));
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

  if ($tableNum === '' || !preg_match('/^\d+$/', $tableNum)) api_error(400, 'Некорректный номер стола');
  if ($guests <= 0 || $guests > 99) api_error(400, 'Некорректное кол-во гостей');
  if ($name === '' || mb_strlen($name) > 80) api_error(400, 'Некорректное имя');

  $phoneNorm = api_normalize_e164_phone($phone);
  if ($phoneNorm === '') api_error(400, $trFor('phone_invalid'));
  $waPhoneNorm = api_normalize_e164_phone($waPhone);

  $comment = str_replace(["\r\n", "\r"], "\n", $comment);
  if (mb_strlen($comment) > 600) $comment = mb_substr($comment, 0, 600);
  $preorder = str_replace(["\r\n", "\r"], "\n", $preorder);
  if (mb_strlen($preorder) > 1200) $preorder = mb_substr($preorder, 0, 1200);
  $preorderRu = str_replace(["\r\n", "\r"], "\n", $preorderRu);
  if (mb_strlen($preorderRu) > 1200) $preorderRu = mb_substr($preorderRu, 0, 1200);
  if ($guests > 5 && trim($preorder) === '') api_error(400, $trFor('preorder_required'));

  $displayTzName = (string)($ctx['displayTzName'] ?? 'Asia/Ho_Chi_Minh');
  $displayTz = new DateTimeZone($displayTzName);
  $startDt = api_parse_datetime_local($start, $displayTz);
  if (!$startDt) api_error(400, 'Некорректное время');

  $db = $ctx['db'] ?? null;
  if (!($db instanceof \App\Classes\Database)) api_error(500, 'DB не настроена');

  $metaRepo = $ctx['metaRepo'] ?? null;
  if (!($metaRepo instanceof \App\Classes\MetaRepository)) {
    $metaRepo = new \App\Classes\MetaRepository($db);
  }
  $workdayKey = 'reservations_latest_workday';
  $weekendKey = 'reservations_latest_weekend';
  $vals = $metaRepo->getMany([$workdayKey, $weekendKey]);
  $latestWorkday = array_key_exists($workdayKey, $vals) ? trim((string)$vals[$workdayKey]) : (string)($ctx['latestWorkday'] ?? '21:00');
  $latestWeekend = array_key_exists($weekendKey, $vals) ? trim((string)$vals[$weekendKey]) : (string)($ctx['latestWeekend'] ?? '22:00');
  if ($latestWorkday === '') $latestWorkday = '21:00';
  if ($latestWeekend === '') $latestWeekend = '22:00';

  $reqDay = (int)$startDt->format('N');
  $limitStr = ($reqDay >= 1 && $reqDay <= 4) ? $latestWorkday : $latestWeekend;
  $limitParts = explode(':', $limitStr);
  $limitH = (int)($limitParts[0] ?? 21);
  $limitM = (int)($limitParts[1] ?? 0);
  $reqH = (int)$startDt->format('H');
  $reqM = (int)$startDt->format('i');
  if ($reqH > $limitH || ($reqH === $limitH && $reqM > $limitM)) {
    api_error(400, 'Извините, мы скоро закрываемся, забронировать столик на это время уже нельзя.');
  }

  $tg = is_array($payload['tg'] ?? null) ? $payload['tg'] : [];
  $tgUid = isset($tg['user_id']) ? (int)$tg['user_id'] : 0;
  $tgUn = strtolower(trim((string)($tg['username'] ?? '')));
  $tgUn = ltrim($tgUn, '@');
  if ($waPhoneNorm !== '') {
    $tgUid = 0;
    $tgUn = '';
  }

  $db->createReservationsTable();
  $resTable = $db->t('reservations');

  if ($waPhoneNorm === '' && $tgUid <= 0) api_error(400, 'Мессенджер не привязан');

  $existing = null;
  try {
    $existing = $db->query(
      "SELECT * FROM {$resTable}
       WHERE start_time = ?
         AND duration = ?
         AND guests = ?
         AND table_num = ?
         AND phone = ?
         AND name = ?
       ORDER BY id DESC
       LIMIT 1",
      [
        $startDt->format('Y-m-d H:i:s'),
        $duration_m,
        $guests,
        $tableNum,
        $phoneNorm,
        $name,
      ]
    )->fetch();
  } catch (\Throwable $e) {
    $existing = null;
  }

  $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
  if ($tgToken === '' && $waPhoneNorm === '') api_error(500, 'Telegram bot token not configured');

  $qrUrl = '';
  $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $qrCode = '';
  $resId = 0;
  $msgId = 0;
  if (is_array($existing) && !empty($existing['id'])) {
    $resId = (int)$existing['id'];
    $qrCode = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($existing['qr_code'] ?? '')));
    $qrUrl = (string)($existing['qr_url'] ?? '');
    $msgId = (int)($existing['tg_message_id'] ?? 0);
  } else {
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
      $qrCode,
    ]);
    $resId = (int)$db->getPdo()->lastInsertId();
  }

  $mgrPayload = [
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
    if ($msgId <= 0) {
      $msgId = reservations_send_manager_booking($db, $resTable, (int)$resId, $mgrPayload, $keyboard);
    }
  } catch (\Throwable $e) {
    $msgId = 0;
  }
  if ($msgId <= 0) api_error(500, 'Не удалось отправить сообщение в Telegram');

  if ($waPhoneNorm !== '') {
    $waSecret = trim((string)($_ENV['WA_NODE_SECRET'] ?? ($_ENV['WA_BRIDGE_SECRET'] ?? '')));
    if ($waSecret === '') api_error(500, $trFor('err_wa_not_configured'));

    $mgrPhone = '+84396314266';
    $mgrWaLink = 'https://wa.me/84396314266';

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
    $waText .= "\n\n" . $trFor('contact_manager') . ' ' . $mgrPhone . "\n" . $mgrWaLink;

    $sent = wa_bridge_send($waPhoneNorm, $waText);
    if (!$sent) api_error(500, $trFor('err_wa_send_failed'));

    api_ok(['id' => $resId, 'qr_code' => $qrCode]);
  }

  $mgrPhone = '+84396314266';
  $mgrWaLink = 'https://wa.me/84396314266';

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
  $userText .= "\n\n" . htmlspecialchars($trFor('contact_manager')) . ' <b>' . htmlspecialchars($mgrPhone) . '</b>' . "\n";
  $userText .= '<a href="' . htmlspecialchars($mgrWaLink) . '">WhatsApp</a>';

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
  $data = $resp ? json_decode((string)$resp, true) : null;

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
    $data = $resp ? json_decode((string)$resp, true) : null;
  }

  if (!is_array($data) || empty($data['ok'])) api_error(500, 'Не удалось отправить сообщение гостю в Telegram');
  api_ok(['id' => $resId, 'qr_code' => $qrCode]);
}
