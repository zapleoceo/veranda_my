<?php
if (file_exists(dirname(__DIR__) . '/.env')) {
    $lines = file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || strpos($t, '#') === 0) continue;
        if (strpos($t, '=') === false) continue;
        [$name, $value] = explode('=', $line, 2);
        $_ENV[$name] = trim($value);
    }
}

require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/src/classes/Database.php';
require_once dirname(__DIR__) . '/src/classes/MetaRepository.php';
require_once dirname(__DIR__) . '/src/classes/PosterAPI.php';
require_once dirname(__DIR__) . '/src/classes/TelegramBot.php';

require_once __DIR__ . '/Model.php';

veranda_require('reservations');

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
    $spotTzName = 'Asia/Ho_Chi_Minh';
}
$spotTz = new DateTimeZone($spotTzName);
$parseSpotDt = function ($s) use ($spotTz) {
    $v = trim((string)$s);
    if ($v === '') return null;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v, $spotTz);
    if ($dt instanceof DateTimeImmutable) return $dt;
    try { return new DateTimeImmutable($v, $spotTz); } catch (Throwable $e) { return null; }
};
$fmtSpotDt = function ($s, string $fmt = 'd.m.Y H:i') use ($parseSpotDt) {
    $dt = $parseSpotDt($s);
    return $dt ? $dt->format($fmt) : '';
};
$fmtSpotDateTimeParts = function ($s) use ($parseSpotDt): array {
    $dt = $parseSpotDt($s);
    if (!$dt) return ['', ''];
    return [$dt->format('d.m.Y'), $dt->format('H:i')];
};

$db = new \App\Classes\Database(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_NAME'] ?? 'veranda_my',
    $_ENV['DB_USER'] ?? 'veranda_my',
    $_ENV['DB_PASS'] ?? '',
    (string)($_ENV['DB_TABLE_SUFFIX'] ?? '')
);
$db->createReservationsTable();
$resTable = $db->t('reservations');
try {
    $db->pdo->exec("ALTER TABLE {$resTable} ADD COLUMN poster_id INT NULL");
} catch (\Throwable $e) {}
try {
    $db->pdo->exec("ALTER TABLE {$resTable} ADD COLUMN is_poster_pushed TINYINT(1) DEFAULT 0");
} catch (\Throwable $e) {}
try {
    $db->pdo->exec("ALTER TABLE {$resTable} ADD COLUMN tg_message_id BIGINT NULL");
} catch (\Throwable $e) {}

$model = new \Reservations\Model($db);

// Check permissions for Poster button
$userPermissions = veranda_get_user_permissions($db, $_SESSION['user_email'] ?? '');
$hasPosterAccess = !empty($userPermissions['vposter_button']);
$canManageTables = function_exists('veranda_can') && veranda_can('admin');

$resHallId = max(1, (int)($_GET['hall_id'] ?? 2));
$resSpotId = max(1, (int)($_GET['spot_id'] ?? ($_ENV['POSTER_SPOT_ID'] ?? 1)));
$resMetaKey = 'reservations_allowed_scheme_nums_hall_' . $resHallId;
$resCapsMetaKey = 'reservations_table_caps_hall_' . $resHallId;
$resSoonKey = 'reservations_soon_booking_hours';
$resMinPreorderKey = 'preorder_min_per_guest_vnd';
$resSoonHours = 2;
$resMinPreorderPerGuest = 100000;
$resAllowedNums = [];
$resCapsByNum = [];
$resHallTables = [];

$ajax = $_GET['ajax'] ?? '';

if ($ajax === 'get_res') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    $row = $model->getReservation($id);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Not found']);
        exit;
    }
    echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'save_res') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid ID']);
        exit;
    }
    $fields = [
        'start_time', 'guests', 'table_num', 'name', 'phone', 
        'whatsapp_phone', 'comment', 'preorder_text', 'preorder_ru',
        'tg_user_id', 'tg_username', 'zalo_user_id', 'zalo_phone',
        'lang', 'total_amount', 'qr_url', 'qr_code', 'duration'
    ];
    $sets = [];
    $params = [];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $sets[] = "{$f} = ?";
            $params[] = $_POST[$f] === '' ? null : $_POST[$f];
        }
    }
    if (empty($sets)) {
        echo json_encode(['ok' => false, 'error' => 'No fields to update']);
        exit;
    }
    try {
        $model->updateReservation($id, $sets, $params);
        echo json_encode(['ok' => true]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($ajax === 'vposter') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$hasPosterAccess) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'У вас нет прав для создания брони в Poster'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = $model->getReservation($id);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Reservation not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($_ENV['POSTER_API_TOKEN'])) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Poster API not configured'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_once dirname(__DIR__) . '/src/classes/PosterReservationHelper.php';
    $spotId = (string)($_ENV['POSTER_SPOT_ID'] ?? '1');
    $actor = (string)($_SESSION['user_email'] ?? '');
    $res = \App\Classes\PosterReservationHelper::pushToPoster($db, $_ENV['POSTER_API_TOKEN'], $id, $spotId, $actor);
    if (!$res['ok']) {
        http_response_code(500);
    } else {
        // Remove Telegram button
        $rowMsg = $model->getTgMessageId($id);
        if ($rowMsg && !empty($rowMsg['tg_message_id'])) {
            require_once dirname(__DIR__) . '/src/classes/TelegramBot.php';
            $tgToken = (string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? '');
            $tgChatId = (string)($_ENV['TELEGRAM_GROUP_ID'] ?? $_ENV['TELEGRAM_CHAT_ID'] ?? '');
            if ($tgToken !== '' && $tgChatId !== '') {
                $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
                $startDt = $parseSpotDt($row['start_time']);
                $datePart = $startDt ? $startDt->format('Y-m-d') : '';
                $timePart = $startDt ? $startDt->format('H:i') : '';

                $msgText = '<b>Бронь с сайта #' . htmlspecialchars((string)$id) . '</b>' . "\n";
                if ($datePart !== '') $msgText .= 'Дата: <b>' . htmlspecialchars($datePart) . '</b>' . "\n";
                if ($timePart !== '') $msgText .= 'Время: <b>' . htmlspecialchars($timePart) . '</b>' . "\n";
                $msgText .= 'Кол-во человек: <b>' . htmlspecialchars((string)$row['guests']) . '</b>' . "\n";
                $msgText .= 'Номер стола: <b>' . htmlspecialchars((string)$row['table_num']) . '</b>' . "\n";
                $msgText .= 'Имя: <b>' . htmlspecialchars((string)$row['name']) . '</b>' . "\n";
                $msgText .= 'Номер телефона: <b>' . htmlspecialchars((string)$row['phone']) . '</b>';
                if (!empty($row['comment'])) {
                    $msgText .= "\n<b>Комментарий:</b>\n" . htmlspecialchars((string)$row['comment']);
                }

                if (!empty($res['duplicate'])) {
                    $msgText .= "\n\n🚀 <b>Уже была в Poster</b> (дубль предотвращен)";
                } else {
                    $msgText .= "\n\n🚀 <b>Отправлено в Poster</b> (через сайт)";
                }

                $bot->editMessageText((int)$rowMsg['tg_message_id'], $msgText, []);
            }
        }
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'resend') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $row = $model->getReservation($id);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Reservation not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tgToken = trim((string)($_ENV['TELEGRAM_BOT_TOKEN'] ?? $_ENV['TG_BOT_TOKEN'] ?? ''));
    $tgChatId = trim((string)($_ENV['TELEGRAM_CHAT_ID'] ?? $_ENV['TG_CHAT_ID'] ?? ''));
    if ($tgChatId === '') $tgChatId = '3397075474';
    $tgThreadId = trim((string)($_ENV['TABLE_RESERVATION_THREAD_ID'] ?? ''));
    $tgThreadNum = $tgThreadId !== '' ? (int)$tgThreadId : 1938;
    if ($tgThreadNum <= 0) $tgThreadNum = 1938;
    $target = strtolower(trim((string)($_POST['target'] ?? 'both')));
    if (!in_array($target, ['both', 'guest', 'manager'], true)) $target = 'both';

    if ($tgToken === '' || $tgChatId === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Telegram not configured'], JSON_UNESCAPED_UNICODE);
        exit;
    }

        $startDt = $parseSpotDt($row['start_time']);
        if (!$startDt) $startDt = new DateTimeImmutable('now', $spotTz);
    
    // Group Message
    $tgUid = (int)($row['tg_user_id'] ?? 0);
    $tgUn = strtolower(trim((string)($row['tg_username'] ?? '')));
    $tgUn = ltrim($tgUn, '@');
    $waPhone = trim((string)($row['whatsapp_phone'] ?? ''));
    $waDigits = preg_replace('/\D+/', '', $waPhone);
    $waDigits = trim((string)$waDigits);
    $waPhoneNorm = ($waDigits !== '' && preg_match('/^[1-9]\d{8,14}$/', $waDigits)) ? ('+' . $waDigits) : '';
    $guestChannel = $waPhoneNorm !== '' ? 'whatsapp' : ($tgUid > 0 ? 'telegram' : '');

    $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
    $okGroup = true;
    if ($target === 'both' || $target === 'manager') {
        $code = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)($row['qr_code'] ?? '')));
        if ($code === '') $code = (string)$row['id'];
        $text = '<b>[Повтор] Новая бронь с сайта #' . htmlspecialchars($code) . '</b>' . "\n";
        $text .= 'Дата: <b>' . htmlspecialchars($startDt->format('Y-m-d')) . '</b>' . "\n";
        $text .= 'Время: <b>' . htmlspecialchars($startDt->format('H:i')) . '</b>' . "\n";
        $text .= 'Кол-во человек: <b>' . htmlspecialchars((string)$row['guests']) . '</b>' . "\n";
        $text .= 'Номер стола: <b>' . htmlspecialchars($row['table_num']) . '</b>' . "\n";
        $text .= 'Имя: <b>' . htmlspecialchars($row['name']) . '</b>' . "\n";
        $text .= 'Номер телефона: <b>' . htmlspecialchars($row['phone']) . '</b>';
        if ($row['comment'] !== '') {
            $text .= "\n<b>Комментарий:</b>\n" . htmlspecialchars($row['comment']);
        }
        $preForGroup = $row['preorder_ru'] !== '' ? $row['preorder_ru'] : $row['preorder_text'];
        if ($preForGroup !== '') {
            $text .= "\n<b>Предзаказ:</b>\n" . htmlspecialchars($preForGroup);
        }
        if ($waPhoneNorm !== '') {
            $waClean = preg_replace('/\D+/', '', $waPhoneNorm);
            $text .= "\nWhatsApp: <a href=\"https://wa.me/" . htmlspecialchars($waClean) . "\">+" . htmlspecialchars($waClean) . "</a>";
        } elseif ($tgUn !== '' || $tgUid > 0) {
            $text .= "\nTelegram: ";
            if ($tgUn !== '') {
                $text .= '<a href="https://t.me/' . htmlspecialchars($tgUn) . '">@' . htmlspecialchars($tgUn) . '</a>';
                if ($tgUid > 0) $text .= ' · <a href="tg://user?id=' . htmlspecialchars((string)$tgUid) . '">Открыть чат</a>';
            } elseif ($tgUid > 0) {
                $text .= '<a href="tg://user?id=' . htmlspecialchars((string)$tgUid) . '">Открыть чат</a> (id ' . htmlspecialchars((string)$tgUid) . ')';
            }
        }
        if (!empty($row['zalo_phone'])) {
            $text .= "\nZalo: <a href=\"https://zalo.me/" . htmlspecialchars(ltrim($row['zalo_phone'], '+')) . "\">" . htmlspecialchars($row['zalo_phone']) . "</a>";
        }
        $text .= "\n\n@Ollushka90 @ce_akh1 свяжитесь с гостем";
        $okGroup = $bot->sendMessage($text, $tgThreadNum > 0 ? $tgThreadNum : null);
    }

    // Guest Message (localized to reservation language)
    $okGuest = true;
    if (($target === 'both' || $target === 'guest') && $guestChannel !== '') {
        $langRow = strtolower(trim((string)($row['lang'] ?? 'ru')));
        if (!in_array($langRow, ['ru', 'en', 'vi'], true)) $langRow = 'ru';
        $T = [
            'ru' => [
                'thanks_title' => 'Спасибо!',
                'thanks_body' => 'Мы с вами свяжемся в ближайшее время.',
                'payment_title' => 'Оплата предзаказа',
                'payment_body' => 'Пожалуйста, отсканируйте QR-код для оплаты предзаказа. В назначении платежа уже указан номер вашей брони.',
                'payment_link' => 'Ссылка на QR-код для оплаты',
                'booking_title' => 'Ваша бронь',
                'date' => 'Дата',
                'time' => 'Время',
                'guests' => 'Кол-во человек',
                'table' => 'Номер стола',
                'name' => 'Имя',
                'phone' => 'Номер телефона',
                'comment' => 'Комментарий',
                'preorder' => 'Предзаказ',
            ],
            'en' => [
                'thanks_title' => 'Thank you!',
                'thanks_body' => 'We will contact you shortly.',
                'payment_title' => 'Pre-order payment',
                'payment_body' => 'Please scan the QR code to pay for the pre-order. The payment description already contains your reservation number.',
                'payment_link' => 'Payment QR link',
                'booking_title' => 'Your reservation',
                'date' => 'Date',
                'time' => 'Time',
                'guests' => 'Guests',
                'table' => 'Table',
                'name' => 'Name',
                'phone' => 'Phone',
                'comment' => 'Comment',
                'preorder' => 'Pre-order',
            ],
            'vi' => [
                'thanks_title' => 'Cảm ơn!',
                'thanks_body' => 'Chúng tôi sẽ liên hệ với bạn sớm.',
                'payment_title' => 'Thanh toán đặt trước',
                'payment_body' => 'Vui lòng quét QR để thanh toán đặt trước. Nội dung chuyển khoản đã có mã đặt bàn của bạn.',
                'payment_link' => 'Link QR thanh toán',
                'booking_title' => 'Đặt bàn của bạn',
                'date' => 'Ngày',
                'time' => 'Giờ',
                'guests' => 'Số khách',
                'table' => 'Bàn',
                'name' => 'Tên',
                'phone' => 'Số điện thoại',
                'comment' => 'Ghi chú',
                'preorder' => 'Đặt trước',
            ],
        ];
        $tr = function (string $k) use ($T, $langRow): string { return $T[$langRow][$k] ?? $k; };

        $qrUrl = (string)($row['qr_url'] ?? '');
        $plainUserText = $tr('thanks_title') . ' ' . $tr('thanks_body') . "\n\n";
        if ($qrUrl !== '') {
            $plainUserText .= $tr('payment_title') . "\n";
            $plainUserText .= $tr('payment_body') . "\n\n";
            $plainUserText .= $tr('payment_link') . ': ' . $qrUrl . "\n\n";
        }
        $plainUserText .= $tr('booking_title') . ' #' . $code . "\n";
        $plainUserText .= $tr('date') . ': ' . $startDt->format('Y-m-d') . "\n";
        $plainUserText .= $tr('time') . ': ' . $startDt->format('H:i') . "\n";
        $plainUserText .= $tr('guests') . ': ' . (string)($row['guests'] ?? '') . "\n";
        $plainUserText .= $tr('table') . ': ' . (string)($row['table_num'] ?? '') . "\n";
        $plainUserText .= $tr('name') . ': ' . (string)($row['name'] ?? '') . "\n";
        $plainUserText .= $tr('phone') . ': ' . (string)($row['phone'] ?? '');
        if ((string)($row['comment'] ?? '') !== '') {
            $plainUserText .= "\n\n" . $tr('comment') . ":\n" . (string)$row['comment'];
        }
        if ((string)($row['preorder_text'] ?? '') !== '') {
            $plainUserText .= "\n\n" . $tr('preorder') . ":\n" . (string)$row['preorder_text'];
        }

        $userText = '<b>' . htmlspecialchars($tr('thanks_title')) . '</b> ' . htmlspecialchars($tr('thanks_body')) . "\n\n";
        if ($qrUrl !== '') {
            $userText .= '<b>' . htmlspecialchars($tr('payment_title')) . "</b>\n";
            $userText .= htmlspecialchars($tr('payment_body')) . "\n\n";
            $userText .= '<a href="' . htmlspecialchars($qrUrl) . '">' . htmlspecialchars($tr('payment_link')) . '</a>' . "\n\n";
        }
        $userText .= '<b>' . htmlspecialchars($tr('booking_title')) . ' #' . htmlspecialchars($code) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('date')) . ': <b>' . htmlspecialchars($startDt->format('Y-m-d')) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('time')) . ': <b>' . htmlspecialchars($startDt->format('H:i')) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('guests')) . ': <b>' . htmlspecialchars((string)($row['guests'] ?? '')) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('table')) . ': <b>' . htmlspecialchars((string)($row['table_num'] ?? '')) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('name')) . ': <b>' . htmlspecialchars((string)($row['name'] ?? '')) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('phone')) . ': <b>' . htmlspecialchars((string)($row['phone'] ?? '')) . '</b>';
        if ((string)($row['comment'] ?? '') !== '') {
            $userText .= "\n<b>" . htmlspecialchars($tr('comment')) . ":</b>\n" . htmlspecialchars((string)$row['comment']);
        }
        if ((string)($row['preorder_text'] ?? '') !== '') {
            $userText .= "\n<b>" . htmlspecialchars($tr('preorder')) . ":</b>\n" . htmlspecialchars((string)$row['preorder_text']);
        }

        $ch = curl_init();
        if ($guestChannel === 'whatsapp') {
            $waToken = trim((string)($_ENV['WHATSAPP_TOKEN'] ?? ''));
            $waInstanceId = trim((string)($_ENV['WHATSAPP_INSTANCE_ID'] ?? ''));
            if ($waToken === '' || $waInstanceId === '') {
                $okGuest = false;
            } else {
                try {
                    require_once dirname(__DIR__) . '/src/classes/WhatsAppAPI.php';
                    $wa = new \App\Classes\WhatsAppAPI($waToken, $waInstanceId);
                    $sent = $wa->sendMessage($waPhoneNorm, $plainUserText);
                    if (!$sent) $okGuest = false;
                } catch (\Throwable $e) {
                    $okGuest = false;
                }
            }
        } elseif ($qrUrl !== '') {
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
        $data = $guestChannel === 'whatsapp' ? ['ok' => $okGuest] : ($resp ? json_decode($resp, true) : null);
        
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
            $okGuest = false;
        }

        // Send Zalo confirmation if linked
        if (!empty($row['zalo_phone']) && !empty($_ENV['ZALO_OA_TOKEN'])) {
            try {
                require_once dirname(__DIR__) . '/src/classes/ZaloAPI.php';
                $zalo = new \App\Classes\ZaloAPI($_ENV['ZALO_OA_TOKEN']);
                
                $zaloText = $tr('thanks_title') . ' ' . $tr('thanks_body') . "\n\n";
                $zaloText .= $tr('booking_title') . " #" . $code . "\n";
                $zaloText .= $tr('date') . ': ' . $startDt->format('Y-m-d') . "\n";
                $zaloText .= $tr('time') . ': ' . $startDt->format('H:i') . "\n";
                $zaloText .= $tr('table') . ': ' . $row['table_num'] . "\n";
                $zaloText .= $tr('guests') . ': ' . $row['guests'] . "\n";
                
                if (!empty($_ENV['ZALO_ZNS_TEMPLATE_ID'])) {
                    $zalo->sendZNS($row['zalo_phone'], $_ENV['ZALO_ZNS_TEMPLATE_ID'], [
                        'date' => $startDt->format('Y-m-d'),
                        'time' => $startDt->format('H:i'),
                        'table' => $row['table_num'],
                        'guests' => (string)$row['guests'],
                        'name' => $row['name']
                    ]);
                }
            } catch (\Throwable $e_zalo) {
                error_log("Zalo re-send error: " . $e_zalo->getMessage());
            }
        }
    } else {
        if ($target === 'both' || $target === 'guest') $okGuest = false;
        else $okGuest = null;
    }

    $respGroup = ($target === 'guest') ? null : $okGroup;
    $respGuest = ($target === 'manager') ? null : $okGuest;

    echo json_encode([
        'ok' => true,
        'group_ok' => $respGroup,
        'guest_ok' => $respGuest,
        'has_tg' => $tgUid > 0,
        'guest_channel' => $guestChannel
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'toggle_deleted') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid ID'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $deleted = (int)($_POST['deleted'] ?? 1) === 1;
    $userEmail = (string)($_SESSION['user_email'] ?? '');

    try {
        $row = $model->toggleDeleted($id, $deleted, $userEmail);
        $deletedAt = (string)($row['deleted_at'] ?? '');
        $deletedBy = (string)($row['deleted_by'] ?? '');
        $isDeleted = $deletedAt !== '' && $deletedAt !== '0000-00-00 00:00:00';
        echo json_encode([
            'ok' => true,
            'deleted' => $isDeleted,
            'deleted_at' => $isDeleted ? date('d.m.Y H:i', strtotime($deletedAt)) : '',
            'deleted_by' => $deletedBy,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'DB error'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($ajax === 'res_table_update') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$canManageTables) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $hallId = max(1, (int)($_POST['hall_id'] ?? $resHallId));
    $spotId = max(1, (int)($_POST['spot_id'] ?? $resSpotId));
    $schemeNum = (int)($_POST['scheme_num'] ?? 0);
    $allowed = (int)($_POST['allowed'] ?? 0) === 1;
    $cap = (int)($_POST['cap'] ?? 0);
    if ($schemeNum < 1 || $schemeNum > 500) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad scheme_num'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($cap < 0) $cap = 0;
    if ($cap > 999) $cap = 999;

    $metaRepo = new \App\Classes\MetaRepository($db);
    $mk = 'reservations_allowed_scheme_nums_hall_' . $hallId;
    $ck = 'reservations_table_caps_hall_' . $hallId;
    $saved = $metaRepo->getMany([$mk, $ck]);

    $nums = [];
    $stored = array_key_exists($mk, $saved) ? trim((string)$saved[$mk]) : '';
    if ($stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                $n = (int)$v;
                if ($n >= 1 && $n <= 500) $nums[(string)$n] = true;
            }
        } else {
            foreach (explode(',', $stored) as $part) {
                $part = trim($part);
                if ($part === '' || !preg_match('/^\d+$/', $part)) continue;
                $n = (int)$part;
                if ($n >= 1 && $n <= 500) $nums[(string)$n] = true;
            }
        }
    }
    if ($allowed) $nums[(string)$schemeNum] = true;
    else unset($nums[(string)$schemeNum]);
    $numsList = array_values(array_map('intval', array_keys($nums)));
    sort($numsList);

    $caps = [];
    $capsStored = array_key_exists($ck, $saved) ? trim((string)$saved[$ck]) : '';
    $capsDecoded = $capsStored !== '' ? json_decode($capsStored, true) : null;
    if (is_array($capsDecoded)) {
        foreach ($capsDecoded as $k => $v) {
            $k = trim((string)$k);
            if (!preg_match('/^\d+$/', $k)) continue;
            $n = (int)$k;
            if ($n < 1 || $n > 500) continue;
            $c = (int)$v;
            if ($c < 0) $c = 0;
            if ($c > 999) $c = 999;
            $caps[(string)$n] = $c;
        }
    }
    $caps[(string)$schemeNum] = $cap;
    ksort($caps, SORT_NATURAL);

    $model->updateMeta($mk, json_encode($numsList, JSON_UNESCAPED_UNICODE));
    $model->updateMeta($ck, json_encode($caps, JSON_UNESCAPED_UNICODE));

    echo json_encode(['ok' => true, 'hall_id' => $hallId, 'spot_id' => $spotId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'res_soon_hours') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$canManageTables) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $h = (int)($_POST['soon_hours'] ?? 2);
    if ($h < 0) $h = 0;
    if ($h > 24) $h = 24;
    $model->updateMeta($resSoonKey, (string)$h);
    echo json_encode(['ok' => true, 'soon_hours' => $h], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'res_preorder_min_per_guest') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$canManageTables) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $v = (int)($_POST['min_per_guest'] ?? 0);
    if ($v < 0) $v = 0;
    if ($v > 10000000) $v = 10000000;
    $model->updateMeta($resMinPreorderKey, (string)$v);
    echo json_encode(['ok' => true, 'min_per_guest' => $v], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($ajax === 'res_hall_data') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$canManageTables) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $hallId = max(1, (int)($_GET['hall_id'] ?? $resHallId));
    $spotId = max(1, (int)($_GET['spot_id'] ?? $resSpotId));
    $metaRepo = new \App\Classes\MetaRepository($db);
    $mk = 'reservations_allowed_scheme_nums_hall_' . $hallId;
    $ck = 'reservations_table_caps_hall_' . $hallId;
    $saved = $metaRepo->getMany([$mk, $ck, $resSoonKey, $resMinPreorderKey]);

    $allowed = [];
    $stored = array_key_exists($mk, $saved) ? trim((string)$saved[$mk]) : '';
    if ($stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                $n = (int)$v;
                if ($n >= 1 && $n <= 500) $allowed[(string)$n] = true;
            }
        }
    }
    $caps = [];
    $capsStored = array_key_exists($ck, $saved) ? trim((string)$saved[$ck]) : '';
    $capsDecoded = $capsStored !== '' ? json_decode($capsStored, true) : null;
    if (is_array($capsDecoded)) {
        foreach ($capsDecoded as $k => $v) {
            $k = trim((string)$k);
            if (!preg_match('/^\d+$/', $k)) continue;
            $n = (int)$k;
            if ($n < 1 || $n > 500) continue;
            $c = (int)$v;
            if ($c < 0) $c = 0;
            if ($c > 999) $c = 999;
            $caps[(string)$n] = $c;
        }
    }
    $soonStored = array_key_exists($resSoonKey, $saved) ? trim((string)$saved[$resSoonKey]) : '';
    $soonHours = ($soonStored !== '' && is_numeric($soonStored)) ? max(0, min(24, (int)$soonStored)) : 2;
    $minStored = array_key_exists($resMinPreorderKey, $saved) ? trim((string)$saved[$resMinPreorderKey]) : '';
    $minPerGuest = ($minStored !== '' && is_numeric($minStored)) ? max(0, (int)$minStored) : 100000;

    $tables = [];
    if (!empty($_ENV['POSTER_API_TOKEN'])) {
        try {
            $apiTables = new \App\Classes\PosterAPI((string)$_ENV['POSTER_API_TOKEN']);
            $rowsTables = $apiTables->request('spots.getTableHallTables', [
                'spot_id' => $spotId,
                'hall_id' => $hallId,
                'without_deleted' => 1,
            ], 'GET');
            $rowsTables = is_array($rowsTables) ? $rowsTables : [];
            foreach ($rowsTables as $t) {
                if (!is_array($t)) continue;
                $tableId = (int)($t['table_id'] ?? 0);
                $tableNum = trim((string)($t['table_num'] ?? ''));
                $tableTitle = trim((string)($t['table_title'] ?? ''));
                $scheme = null;
                if (preg_match('/^\d+$/', $tableTitle)) $scheme = (int)$tableTitle;
                elseif (preg_match('/^\d+$/', $tableNum)) $scheme = (int)$tableNum;
                $schemeStr = $scheme !== null ? (string)$scheme : '';
                $tables[] = [
                    'table_id' => $tableId,
                    'table_num' => $tableNum,
                    'table_title' => $tableTitle,
                    'scheme_num' => $schemeStr,
                    'shape' => (string)($t['table_shape'] ?? ''),
                    'x' => (float)($t['table_x'] ?? 0),
                    'y' => (float)($t['table_y'] ?? 0),
                    'w' => (float)($t['table_width'] ?? 0),
                    'h' => (float)($t['table_height'] ?? 0),
                    'is_allowed' => ($schemeStr !== '' && isset($allowed[$schemeStr])) ? 1 : 0,
                    'cap' => ($schemeStr !== '') ? (int)($caps[$schemeStr] ?? 0) : 0,
                ];
            }
        } catch (\Throwable $e_tbl2) {
        }
    }
    echo json_encode(['ok' => true, 'spot_id' => $spotId, 'hall_id' => $hallId, 'soon_hours' => $soonHours, 'min_preorder_per_guest' => $minPerGuest, 'tables' => $tables], JSON_UNESCAPED_UNICODE);
    exit;
}

$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+1 month'));
$showDeleted = !empty($_GET['show_deleted']);
$showPoster = isset($_GET['show_poster']) ? !empty($_GET['show_poster']) : true;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d', strtotime('+1 month'));

$sort = $_GET['sort'] ?? 'start_time';
$order = strtolower($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$validSorts = ['id', 'qr_code', 'created_at', 'start_time', 'table_num', 'guests', 'name', 'phone', 'total_amount'];
if (!in_array($sort, $validSorts, true)) $sort = 'start_time';

$rows = $model->getReservationsList($dateFrom, $dateTo, $showDeleted, $sort, $order);

$defaultCaps = [
    '1' => 8, '2' => 8, '3' => 8,
    '4' => 5, '5' => 5, '6' => 5, '7' => 5, '8' => 5,
    '9' => 8,
    '10' => 2, '11' => 2, '12' => 2, '13' => 2,
    '14' => 3, '15' => 3, '16' => 3,
    '17' => 5, '18' => 5, '19' => 5, '20' => 5, '21' => 5,
    '22' => 15,
];

try {
    $metaRepo = new \App\Classes\MetaRepository($db);
    $saved = $metaRepo->getMany([$resMetaKey, $resCapsMetaKey, $resSoonKey, $resMinPreorderKey]);

    $stored = array_key_exists($resMetaKey, $saved) ? trim((string)$saved[$resMetaKey]) : '';
    if ($stored !== '') {
        $decoded = json_decode($stored, true);
        if (is_array($decoded)) {
            foreach ($decoded as $v) {
                $n = (int)$v;
                if ($n >= 1 && $n <= 500) $resAllowedNums[(string)$n] = true;
            }
        } else {
            foreach (explode(',', $stored) as $part) {
                $part = trim($part);
                if ($part === '' || !preg_match('/^\d+$/', $part)) continue;
                $n = (int)$part;
                if ($n >= 1 && $n <= 500) $resAllowedNums[(string)$n] = true;
            }
        }
    }

    $capsStored = array_key_exists($resCapsMetaKey, $saved) ? trim((string)$saved[$resCapsMetaKey]) : '';
    $capsDecoded = $capsStored !== '' ? json_decode($capsStored, true) : null;
    if (is_array($capsDecoded)) {
        foreach ($capsDecoded as $k => $v) {
            $k = trim((string)$k);
            if (!preg_match('/^\d+$/', $k)) continue;
            $n = (int)$k;
            if ($n < 1 || $n > 500) continue;
            $c = (int)$v;
            if ($c < 0) $c = 0;
            if ($c > 999) $c = 999;
            $resCapsByNum[(string)$n] = $c;
        }
    } else {
        $resCapsByNum = $defaultCaps;
    }

    $soonStored = array_key_exists($resSoonKey, $saved) ? trim((string)$saved[$resSoonKey]) : '';
    if ($soonStored !== '' && is_numeric($soonStored)) {
        $resSoonHours = max(0, min(24, (int)$soonStored));
    }
    $minStored = array_key_exists($resMinPreorderKey, $saved) ? trim((string)$saved[$resMinPreorderKey]) : '';
    if ($minStored !== '' && is_numeric($minStored)) {
        $resMinPreorderPerGuest = max(0, (int)$minStored);
    }
} catch (\Throwable $e_meta) {
}

if (!empty($_ENV['POSTER_API_TOKEN'])) {
    try {
        $apiTables = new \App\Classes\PosterAPI((string)$_ENV['POSTER_API_TOKEN']);
        $rowsTables = $apiTables->request('spots.getTableHallTables', [
            'spot_id' => $resSpotId,
            'hall_id' => $resHallId,
            'without_deleted' => 1,
        ], 'GET');
        $rowsTables = is_array($rowsTables) ? $rowsTables : [];
        foreach ($rowsTables as $t) {
            if (!is_array($t)) continue;
            $tableId = (int)($t['table_id'] ?? 0);
            $tableNum = trim((string)($t['table_num'] ?? ''));
            $tableTitle = trim((string)($t['table_title'] ?? ''));
            $scheme = null;
            if (preg_match('/^\d+$/', $tableTitle)) $scheme = (int)$tableTitle;
            elseif (preg_match('/^\d+$/', $tableNum)) $scheme = (int)$tableNum;
            $schemeStr = $scheme !== null ? (string)$scheme : '';

            $resHallTables[] = [
                'table_id' => $tableId,
                'table_num' => $tableNum,
                'table_title' => $tableTitle,
                'scheme_num' => $schemeStr,
                'shape' => (string)($t['table_shape'] ?? ''),
                'x' => (float)($t['table_x'] ?? 0),
                'y' => (float)($t['table_y'] ?? 0),
                'w' => (float)($t['table_width'] ?? 0),
                'h' => (float)($t['table_height'] ?? 0),
                'is_allowed' => ($schemeStr !== '' && isset($resAllowedNums[$schemeStr])) ? 1 : 0,
                'cap' => ($schemeStr !== '') ? (int)($resCapsByNum[$schemeStr] ?? ($defaultCaps[$schemeStr] ?? 0)) : 0,
            ];
        }
    } catch (\Throwable $e_tbl) {
    }
}

// FETCH FROM POSTER
$posterRows = [];
if ($showPoster && !empty($_ENV['POSTER_API_TOKEN'])) {
    try {
        $api = new \App\Classes\PosterAPI($_ENV['POSTER_API_TOKEN']);
        
        $spotId = (int)($_ENV['POSTER_SPOT_ID'] ?? 1);
        if ($spotId <= 0) $spotId = 1;

        // Fetch all tables to map table_id to table_title
        $tableNameMap = [];
        try {
            $allTables = $api->request('spots.getTableHallTables', ['spot_id' => $spotId, 'without_deleted' => 1]);
            if (is_array($allTables)) {
                foreach ($allTables as $t) {
                    if (isset($t['table_id'])) {
                        $id = (int)$t['table_id'];
                        $num = trim((string)($t['table_num'] ?? ''));
                        $title = trim((string)($t['table_title'] ?? ''));
                        $scheme = '';
                        if (preg_match('/^\d+$/', $title)) $scheme = $title;
                        elseif (preg_match('/^\d+$/', $num)) $scheme = $num;
                        if ($scheme !== '') $tableNameMap[$id] = $scheme;
                    }
                }
            }
        } catch (\Throwable $e_tables) {
            // Table mapping failed, will use ID as fallback
        }

        $resp = $api->request('incomingOrders.getReservations', [
            'timezone' => 'client',
        ], 'GET');
        
        if (is_array($resp)) {
            $fromDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateFrom . ' 00:00:00', $spotTz);
            $toDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateTo . ' 23:59:59', $spotTz);
            foreach ($resp as $pr) {
                $status = (int)($pr['status'] ?? 0);
                if (!$showDeleted && $status === 7) continue;
                if ((int)($pr['spot_id'] ?? 0) !== $spotId) continue;
                $dr = trim((string)($pr['date_reservation'] ?? ''));
                $drDt = $parseSpotDt($dr);
                if (!$drDt) continue;
                if ($fromDt instanceof DateTimeImmutable && $drDt < $fromDt) continue;
                if ($toDt instanceof DateTimeImmutable && $drDt > $toDt) continue;
                
                $tId = (int)($pr['table_id'] ?? 0);
                $displayTable = isset($tableNameMap[$tId]) ? $tableNameMap[$tId] : ($tId > 0 ? $tId : '?');
                $comment = (string)($pr['comment'] ?? '');
                $marker = '';
                if ($comment !== '' && preg_match('/\[VERANDA:([A-Z0-9]{6,16})\]/', $comment, $mm)) {
                    $marker = strtoupper((string)($mm[1] ?? ''));
                }

                $posterRows[] = [
                    'incoming_order_id' => (int)($pr['incoming_order_id'] ?? 0),
                    'created_at' => (string)($pr['created_at'] ?? ''),
                    'updated_at' => (string)($pr['updated_at'] ?? ''),
                    'start_time' => $drDt->format('Y-m-d H:i:s'),
                    'table_id' => $tId,
                    'table_num' => (string)$displayTable,
                    'guests' => (int)($pr['guests_count'] ?? 0),
                    'name' => trim(((string)($pr['first_name'] ?? '')) . ' ' . ((string)($pr['last_name'] ?? ''))),
                    'phone' => (string)($pr['phone'] ?? ''),
                    'comment' => $comment,
                    'marker_code' => $marker,
                    'status' => $status,
                    'status_text' => ($status === 7 ? 'Отменено' : ($status === 1 ? 'Принято' : 'Новый')),
                ];
            }
        }
    } catch (\Throwable $e) {
        // Log error to PHP error log for debugging
        error_log("Poster Reservations Error: " . $e->getMessage());
    }
}

$posterById = [];
$posterByMarker = [];
$posterByDayTable = [];
foreach ($posterRows as $pr) {
    $pid = (int)($pr['incoming_order_id'] ?? 0);
    if ($pid > 0) $posterById[$pid] = $pr;
    $mc = (string)($pr['marker_code'] ?? '');
    if ($mc !== '') $posterByMarker[$mc] = $pr;
    $day = (string)substr((string)($pr['start_time'] ?? ''), 0, 10);
    $tbl = (string)($pr['table_num'] ?? '');
    if ($day !== '' && $tbl !== '') {
        $k = $day . '|' . $tbl;
        if (!isset($posterByDayTable[$k])) $posterByDayTable[$k] = [];
        $posterByDayTable[$k][] = $pr;
    }
}

$usedPoster = [];
$viewRows = [];
foreach ($rows as $r) {
    $ourStart = (string)($r['start_time'] ?? '');
    $ourDay = substr($ourStart, 0, 10);
    $ourTable = trim((string)($r['table_num'] ?? ''));
    $ourMarker = strtoupper(trim((string)($r['qr_code'] ?? '')));
    $poster = null;

    $posterId = (int)($r['poster_id'] ?? 0);
    if ($posterId > 0 && isset($posterById[$posterId]) && empty($usedPoster[$posterId])) {
        $poster = $posterById[$posterId];
        $usedPoster[$posterId] = true;
    }

    if ($poster === null && $ourMarker !== '' && isset($posterByMarker[$ourMarker])) {
        $cand = $posterByMarker[$ourMarker];
        $candId = (int)($cand['incoming_order_id'] ?? 0);
        if ($candId > 0 && empty($usedPoster[$candId])) {
            $poster = $cand;
            $usedPoster[$candId] = true;
        }
    }

    if ($poster === null && $ourDay !== '' && $ourTable !== '') {
        $k = $ourDay . '|' . $ourTable;
        $list = $posterByDayTable[$k] ?? [];
        if ($list) {
            $best = null;
            $bestDiff = null;
            $ourTs = strtotime($ourStart);
            foreach ($list as $cand) {
                $candId = (int)($cand['incoming_order_id'] ?? 0);
                if ($candId <= 0 || !empty($usedPoster[$candId])) continue;
                $candTs = strtotime((string)($cand['start_time'] ?? ''));
                if ($ourTs === false || $candTs === false) continue;
                $diff = abs($candTs - $ourTs);
                if ($diff > 1800) continue;
                if ($bestDiff === null || $diff < $bestDiff) {
                    $best = $cand;
                    $bestDiff = $diff;
                }
            }
            if ($best) {
                $poster = $best;
                $usedPoster[(int)$best['incoming_order_id']] = true;
            }
        }
    }

    $viewRows[] = ['our' => $r, 'poster' => $poster];
}

if ($showPoster) {
    foreach ($posterRows as $pr) {
        $pid = (int)($pr['incoming_order_id'] ?? 0);
        if ($pid > 0 && !empty($usedPoster[$pid])) continue;
        $viewRows[] = ['our' => null, 'poster' => $pr];
    }
}

$getSortVal = function (array $row) use ($sort) {
    $our = $row['our'] ?? null;
    $poster = $row['poster'] ?? null;
    $v = '';
    if (is_array($our) && array_key_exists($sort, $our)) $v = $our[$sort];
    elseif ($sort === 'start_time' && is_array($poster)) $v = $poster['start_time'] ?? '';
    elseif ($sort === 'created_at' && is_array($poster)) $v = $poster['created_at'] ?? '';
    elseif ($sort === 'table_num' && is_array($poster)) $v = $poster['table_num'] ?? '';
    elseif ($sort === 'guests' && is_array($poster)) $v = $poster['guests'] ?? 0;
    elseif ($sort === 'name' && is_array($poster)) $v = $poster['name'] ?? '';
    elseif ($sort === 'phone' && is_array($poster)) $v = $poster['phone'] ?? '';
    elseif ($sort === 'id' && is_array($poster)) $v = (int)($poster['incoming_order_id'] ?? 0);
    return $v;
};
usort($viewRows, function ($a, $b) use ($getSortVal, $order) {
    $va = $getSortVal($a);
    $vb = $getSortVal($b);
    if ($order === 'asc') return $va <=> $vb;
    return $vb <=> $va;
});

require __DIR__ . '/view.php';
