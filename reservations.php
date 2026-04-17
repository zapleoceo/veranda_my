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

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/src/classes/Database.php';
require_once __DIR__ . '/src/classes/MetaRepository.php';
require_once __DIR__ . '/src/classes/PosterAPI.php';
require_once __DIR__ . '/src/classes/TelegramBot.php';

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

// Check permissions for Poster button
$userPermissions = veranda_get_user_permissions($db, $_SESSION['user_email'] ?? '');
$hasPosterAccess = !empty($userPermissions['vposter_button']);

$ajax = $_GET['ajax'] ?? '';
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

    $row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
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
    require_once __DIR__ . '/src/classes/PosterReservationHelper.php';
    $spotId = (string)($_ENV['POSTER_SPOT_ID'] ?? '1');
    $actor = (string)($_SESSION['user_email'] ?? '');
    $res = \App\Classes\PosterReservationHelper::pushToPoster($db, $_ENV['POSTER_API_TOKEN'], $id, $spotId, $actor);
    if (!$res['ok']) {
        http_response_code(500);
    } else {
        // Remove Telegram button
        $rowMsg = $db->query("SELECT tg_message_id FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
        if ($rowMsg && !empty($rowMsg['tg_message_id'])) {
            require_once __DIR__ . '/src/classes/TelegramBot.php';
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

    $row = $db->query("SELECT * FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
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
                    require_once __DIR__ . '/src/classes/WhatsAppAPI.php';
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
                require_once __DIR__ . '/src/classes/ZaloAPI.php';
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
        if ($deleted) {
            $db->query("UPDATE {$resTable} SET deleted_at = NOW(), deleted_by = ? WHERE id = ? LIMIT 1", [$userEmail, $id]);
        } else {
            $db->query("UPDATE {$resTable} SET deleted_at = NULL, deleted_by = NULL WHERE id = ? LIMIT 1", [$id]);
        }
        $row = $db->query("SELECT id, deleted_at, deleted_by FROM {$resTable} WHERE id = ? LIMIT 1", [$id])->fetch();
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

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 week'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+1 month'));
$showDeleted = !empty($_GET['show_deleted']);
$showPoster = isset($_GET['show_poster']) ? !empty($_GET['show_poster']) : true;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d', strtotime('-1 week'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d', strtotime('+1 month'));

$sort = $_GET['sort'] ?? 'start_time';
$order = strtolower($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$validSorts = ['id', 'qr_code', 'created_at', 'start_time', 'table_num', 'guests', 'name', 'phone', 'total_amount'];
if (!in_array($sort, $validSorts, true)) $sort = 'start_time';

$where = "DATE(start_time) BETWEEN ? AND ?";
$params = [$dateFrom, $dateTo];
if (!$showDeleted) {
    $where .= " AND deleted_at IS NULL";
}
$rows = $db->query("
    SELECT * 
    FROM {$resTable} 
    WHERE {$where}
    ORDER BY {$sort} {$order}
", $params)->fetchAll();
if (!is_array($rows)) {
    $rows = [];
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

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Брони</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="/assets/app.css?v=20260415_1200">
    <link rel="stylesheet" href="/assets/css/reservations.css?v=20260417_1036">
</head>
<body>
    <div class="container res-page">
        <div class="top-nav">
            <div class="nav-left">
                <a href="/dashboard.php">← Дашборд</a>
                <span class="nav-title">Брони</span>
            </div>
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
        </div>

        <div class="card">
            <div class="res-header">
                <div>
                    <div class="title">Брони</div>
                    <div class="sub">Период и управление заявками</div>
                </div>
                <div class="res-controls">
                    <label class="res-switch">
                        <input id="showPoster" type="checkbox" <?= $showPoster ? 'checked' : '' ?>>
                        <span>Брони Poster</span>
                    </label>
                    <label class="res-switch">
                        <input id="showDeleted" type="checkbox" <?= $showDeleted ? 'checked' : '' ?>>
                        <span>Удалённые</span>
                    </label>
                    <div class="res-colmenu" id="resColMenu">
                        <button type="button" class="res-btn res-colbtn" id="resColBtn">Колонки</button>
                        <div class="res-colpanel" id="resColPanel" hidden></div>
                    </div>
                </div>
            </div>

            <form method="GET" class="filters">
                <div class="date-inputs">
                    <label>
                        Начало
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    </label>
                    <label>
                        Конец
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                    </label>
                </div>
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
                <input type="hidden" name="show_deleted" value="<?= $showDeleted ? '1' : '' ?>">
                <input type="hidden" name="show_poster" value="<?= $showPoster ? '1' : '' ?>">
                <button type="submit" class="btn-primary">Показать</button>
            </form>

            <div class="table-wrap" id="resTableWrap">
                <table class="res-table" id="resTable">
                    <thead>
                        <tr class="res-head-group">
                            <th colspan="10" data-side="site">Сайт</th>
                            <?php if ($showPoster): ?>
                                <th colspan="7" data-side="poster">Poster</th>
                            <?php endif; ?>
                        </tr>
                        <tr class="res-head-cols">
                            <th data-col="id" data-side="site" data-sort="id" data-type="num">ID</th>
                            <th data-col="qr_code" data-side="site" data-sort="qr_code" data-type="text">Код</th>
                            <th data-col="created_at" data-side="site" data-sort="created_at" data-type="date">Создано</th>
                            <th data-col="start_time" data-side="site" data-sort="start_time" data-type="date">Время брони</th>
                            <th data-col="table_num" data-side="site" data-sort="table_num" data-type="num">Стол</th>
                            <th data-col="guests" data-side="site" data-sort="guests" data-type="num">Гостей</th>
                            <th data-col="name" data-side="site" data-sort="name" data-type="text">Гость</th>
                            <th data-col="total_amount" data-side="site" data-sort="total_amount" data-type="num">Сумма</th>
                            <th data-col="qr_url" data-side="site" data-sort="qr_url" data-type="text">QR</th>
                            <th data-col="actions" data-side="site">Действия</th>
                            <?php if ($showPoster): ?>
                                <th data-col="p_id" data-side="poster" data-sort="p_id" data-type="num">Poster ID</th>
                                <th data-col="p_created_at" data-side="poster" data-sort="p_created_at" data-type="date">Создано</th>
                                <th data-col="p_start_time" data-side="poster" data-sort="p_start_time" data-type="date">Время</th>
                                <th data-col="p_table_num" data-side="poster" data-sort="p_table_num" data-type="num">Стол</th>
                                <th data-col="p_guests" data-side="poster" data-sort="p_guests" data-type="num">Гостей</th>
                                <th data-col="p_name" data-side="poster" data-sort="p_name" data-type="text">Гость</th>
                                <th data-col="p_phone" data-side="poster" data-sort="p_phone" data-type="text">Телефон</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($viewRows)): ?>
                            <tr class="res-empty"><td colspan="<?= $showPoster ? 17 : 10 ?>" class="res-empty-cell">Нет броней за выбранный период</td></tr>
                        <?php else: ?>
                            <?php foreach ($viewRows as $pair): ?>
                                <?php
                                    $r = is_array($pair['our'] ?? null) ? $pair['our'] : null;
                                    $p = is_array($pair['poster'] ?? null) ? $pair['poster'] : null;

                                    $tgUsername = (string)(is_array($r) ? ($r['tg_username'] ?? '') : '');
                                    $tgUsername = trim($tgUsername);
                                    if ($tgUsername !== '') {
                                        $tgUsername = ltrim($tgUsername, '@');
                                    }
                                    $waPhone = trim((string)(is_array($r) ? ($r['whatsapp_phone'] ?? '') : ''));
                                    $waDigits = preg_replace('/\D+/', '', $waPhone);
                                    $waDigits = trim((string)$waDigits);
                                    $waPhoneNorm = ($waDigits !== '' && preg_match('/^[1-9]\d{8,14}$/', $waDigits)) ? ('+' . $waDigits) : '';
                                    $deletedAt = (string)(is_array($r) ? ($r['deleted_at'] ?? '') : '');
                                    $deletedBy = (string)(is_array($r) ? ($r['deleted_by'] ?? '') : '');
                                    $isDeleted = $deletedAt !== '' && $deletedAt !== null && $deletedAt !== '0000-00-00 00:00:00';
                                    $deletedAtHuman = $isDeleted ? ($fmtSpotDt($deletedAt) ?: '') : '';
                                    [$createdAtDate, $createdAtTime] = is_array($r) ? $fmtSpotDateTimeParts($r['created_at'] ?? '') : ['', ''];
                                    [$startDate, $startTime] = is_array($r) ? $fmtSpotDateTimeParts($r['start_time'] ?? '') : ['', ''];
                                    [$pCreatedAtDate, $pCreatedAtTime] = is_array($p) ? $fmtSpotDateTimeParts($p['created_at'] ?? '') : ['', ''];
                                    [$pStartDate, $pStartTime] = is_array($p) ? $fmtSpotDateTimeParts($p['start_time'] ?? '') : ['', ''];
                                    $createdAtTs = '';
                                    if (is_array($r) && !empty($r['created_at'])) { $dtTmp = $parseSpotDt($r['created_at']); if ($dtTmp) $createdAtTs = (string)$dtTmp->getTimestamp(); }
                                    $startTs = '';
                                    if (is_array($r) && !empty($r['start_time'])) { $dtTmp = $parseSpotDt($r['start_time']); if ($dtTmp) $startTs = (string)$dtTmp->getTimestamp(); }
                                    $pCreatedAtTs = '';
                                    if (is_array($p) && !empty($p['created_at'])) { $dtTmp = $parseSpotDt($p['created_at']); if ($dtTmp) $pCreatedAtTs = (string)$dtTmp->getTimestamp(); }
                                    $pStartTs = '';
                                    if (is_array($p) && !empty($p['start_time'])) { $dtTmp = $parseSpotDt($p['start_time']); if ($dtTmp) $pStartTs = (string)$dtTmp->getTimestamp(); }
                                    $pIsDeleted = is_array($p) && (int)($p['status'] ?? 0) === 7;
                                    $isMerged = is_array($r) && is_array($p);
                                ?>
                                <tr<?= is_array($r) ? ' data-id="' . htmlspecialchars((string)($r['id'] ?? '')) . '"' : '' ?> class="<?= $isDeleted ? 'is-deleted' : '' ?> <?= $pIsDeleted ? 'is-poster is-deleted' : '' ?> <?= $isMerged ? 'is-merged' : '' ?>">
                                    <td data-label="ID" data-col="id" data-sort-value="<?= is_array($r) ? (int)($r['id'] ?? 0) : '' ?>">
                                        <?= is_array($r) ? ('<div>#' . htmlspecialchars((string)($r['id'] ?? '')) . '</div>') : '<span class="res-muted">—</span>' ?>
                                        <?php if ($isDeleted): ?>
                                            <div class="tag deleted" id="deleted-tag-<?= htmlspecialchars((string)($r['id'] ?? '')) ?>">
                                                Удалено<?= $deletedBy !== '' ? ' · ' . htmlspecialchars($deletedBy) : '' ?>
                                            </div>
                                            <div class="res-muted" id="deleted-meta-<?= htmlspecialchars((string)($r['id'] ?? '')) ?>">
                                                <?= htmlspecialchars($deletedAtHuman) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Код" data-col="qr_code" data-sort-value="<?= is_array($r) ? htmlspecialchars((string)($r['qr_code'] ?? ''), ENT_QUOTES) : '' ?>">
                                        <?php if (is_array($r) && !empty($r['qr_code'])): ?>
                                            <span class="tag"><?= htmlspecialchars((string)$r['qr_code']) ?></span>
                                        <?php else: ?><span class="res-muted">—</span><?php endif; ?>
                                    </td>
                                    <td data-label="Создано" data-col="created_at" data-sort-value="<?= htmlspecialchars((string)$createdAtTs, ENT_QUOTES) ?>">
                                        <?php if ($createdAtDate !== ''): ?>
                                            <div><?= htmlspecialchars($createdAtDate) ?></div>
                                            <div class="res-muted"><?= htmlspecialchars($createdAtTime) ?></div>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Время" class="res-strong" data-col="start_time" data-sort-value="<?= htmlspecialchars((string)$startTs, ENT_QUOTES) ?>">
                                        <?php if ($startDate !== ''): ?>
                                            <div><?= htmlspecialchars($startDate) ?></div>
                                            <div class="res-muted"><?= htmlspecialchars($startTime) ?></div>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Стол" data-col="table_num" data-sort-value="<?= is_array($r) ? (int)preg_replace('/\D+/', '', (string)($r['table_num'] ?? '')) : '' ?>"><?= is_array($r) ? htmlspecialchars((string)$r['table_num']) : '—' ?></td>
                                    <td data-label="Гостей" data-col="guests" data-sort-value="<?= is_array($r) ? (int)($r['guests'] ?? 0) : '' ?>"><?= is_array($r) ? (int)$r['guests'] : '—' ?></td>
                                    <td data-label="Гость" data-col="name" data-sort-value="<?= is_array($r) ? htmlspecialchars(mb_strtolower(trim((string)($r['name'] ?? ''))), ENT_QUOTES) : '' ?>">
                                        <?php if (is_array($r)): ?>
                                            <div class="res-name"><?= htmlspecialchars((string)$r['name']) ?></div>
                                            <div class="res-muted"><?= htmlspecialchars((string)$r['phone']) ?></div>
                                        <?php else: ?>
                                            <span class="res-muted">—</span>
                                        <?php endif; ?>
                                        <?php if ($waPhoneNorm !== ''): ?>
                                            <?php $waClean = preg_replace('/\D+/', '', $waPhoneNorm); ?>
                                            <div class="res-muted"><a class="res-link" href="https://wa.me/<?= htmlspecialchars($waClean) ?>" target="_blank">WA: +<?= htmlspecialchars($waClean) ?></a></div>
                                        <?php elseif ($tgUsername !== ''): ?>
                                            <div class="res-muted"><a class="res-link" href="https://t.me/<?= htmlspecialchars($tgUsername) ?>" target="_blank">TG: @<?= htmlspecialchars($tgUsername) ?></a></div>
                                        <?php endif; ?>
                                        <?php if (is_array($r) && !empty($r['zalo_phone'])): ?>
                                            <div class="res-muted"><a class="res-link" href="https://zalo.me/<?= htmlspecialchars(ltrim((string)$r['zalo_phone'], '+')) ?>" target="_blank">Zalo: <?= htmlspecialchars((string)$r['zalo_phone']) ?></a></div>
                                        <?php endif; ?>
                                        <?php if (is_array($r) && (!empty($r['comment']) || !empty($r['preorder_text']))): ?>
                                            <details class="res-more">
                                                <summary>Комментарий / предзаказ</summary>
                                                <div class="box">
                                                    <?php if (!empty($r['comment'])): ?>
                                                        <div><b>Комментарий:</b> <?= htmlspecialchars((string)$r['comment']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($r['preorder_text'])): ?>
                                                        <div class="res-mt6"><b>Предзаказ:</b><div class="pre"><?= htmlspecialchars((string)$r['preorder_text']) ?></div></div>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Сумма" data-col="total_amount" data-sort-value="<?= is_array($r) ? (float)($r['total_amount'] ?? 0) : '' ?>"><?= (is_array($r) && (float)($r['total_amount'] ?? 0) > 0) ? number_format((float)$r['total_amount'], 0, '.', ' ') . ' ₫' : '—' ?></td>
                                    <td data-label="QR" data-col="qr_url" data-sort-value="<?= is_array($r) ? htmlspecialchars((string)($r['qr_url'] ?? ''), ENT_QUOTES) : '' ?>">
                                        <?php if (is_array($r) && !empty($r['qr_url'])): ?>
                                            <a class="res-link res-link-strong" href="<?= htmlspecialchars((string)$r['qr_url']) ?>" target="_blank">QR</a>
                                        <?php else: ?>
                                            <span class="res-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Действия" data-col="actions">
                                        <?php if (is_array($r)): ?>
                                            <div class="res-actions">
                                                <?php $guestBtnClass = $waPhoneNorm !== '' ? 'contact-wa' : ($tgUsername !== '' || (int)($r['tg_user_id'] ?? 0) > 0 ? 'contact-tg' : ''); ?>
                                                <button type="button" class="res-btn btn-resend <?= htmlspecialchars($guestBtnClass) ?>" data-id="<?= htmlspecialchars((string)$r['id']) ?>" data-target="guest">reG</button>
                                                <button type="button" class="res-btn primary btn-resend" data-id="<?= htmlspecialchars((string)$r['id']) ?>" data-target="manager">reM</button>
                                                <?php if ($hasPosterAccess && empty($r['is_poster_pushed'])): ?>
                                                    <button
                                                        type="button"
                                                        class="res-btn primary btn-vposter"
                                                        data-id="<?= htmlspecialchars((string)$r['id']) ?>"
                                                        data-code="<?= htmlspecialchars((string)($r['qr_code'] ?? '')) ?>"
                                                        data-start="<?= htmlspecialchars((string)($r['start_time'] ?? '')) ?>"
                                                        data-table="<?= htmlspecialchars((string)($r['table_num'] ?? '')) ?>"
                                                        data-guests="<?= htmlspecialchars((string)($r['guests'] ?? '')) ?>"
                                                        data-name="<?= htmlspecialchars((string)($r['name'] ?? '')) ?>"
                                                        data-phone="<?= htmlspecialchars((string)($r['phone'] ?? '')) ?>"
                                                    >вPos</button>
                                                <?php endif; ?>
                                                <button type="button" class="res-btn danger icon btn-delete" data-id="<?= htmlspecialchars((string)$r['id']) ?>"><?= $isDeleted ? '↺' : '✕' ?></button>
                                            </div>
                                            <div class="res-status" id="resend-status-<?= htmlspecialchars((string)$r['id']) ?>"></div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($showPoster): ?>
                                        <td data-label="Poster ID" data-col="p_id" data-sort-value="<?= is_array($p) ? (int)($p['incoming_order_id'] ?? 0) : '' ?>"><?= is_array($p) && !empty($p['incoming_order_id']) ? htmlspecialchars((string)$p['incoming_order_id']) : '—' ?></td>
                                        <td data-label="Создано" data-col="p_created_at" data-sort-value="<?= htmlspecialchars((string)$pCreatedAtTs, ENT_QUOTES) ?>">
                                            <?php if ($pCreatedAtDate !== ''): ?>
                                                <div><?= htmlspecialchars($pCreatedAtDate) ?></div>
                                                <div class="res-muted"><?= htmlspecialchars($pCreatedAtTime) ?></div>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Время" class="res-strong" data-col="p_start_time" data-sort-value="<?= htmlspecialchars((string)$pStartTs, ENT_QUOTES) ?>">
                                            <?php if ($pStartDate !== ''): ?>
                                                <div><?= htmlspecialchars($pStartDate) ?></div>
                                                <div class="res-muted"><?= htmlspecialchars($pStartTime) ?></div>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Стол" data-col="p_table_num" data-sort-value="<?= is_array($p) ? (int)preg_replace('/\D+/', '', (string)($p['table_num'] ?? '')) : '' ?>"><?= is_array($p) ? htmlspecialchars((string)($p['table_num'] ?? '')) : '—' ?></td>
                                        <td data-label="Гостей" data-col="p_guests" data-sort-value="<?= is_array($p) ? (int)($p['guests'] ?? 0) : '' ?>"><?= is_array($p) ? (int)($p['guests'] ?? 0) : '—' ?></td>
                                        <td data-label="Гость" data-col="p_name" data-sort-value="<?= is_array($p) ? htmlspecialchars(mb_strtolower(trim((string)($p['name'] ?? ''))), ENT_QUOTES) : '' ?>"><?= is_array($p) ? htmlspecialchars((string)($p['name'] ?? '')) : '—' ?></td>
                                        <td data-label="Телефон" data-col="p_phone" data-sort-value="<?= is_array($p) ? htmlspecialchars((string)($p['phone'] ?? ''), ENT_QUOTES) : '' ?>"><?= is_array($p) ? htmlspecialchars((string)($p['phone'] ?? '')) : '—' ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- VPoster Confirmation Modal -->
    <div id="vposterModal" class="res-modal" hidden>
        <div class="res-modal-card">
            <div class="res-modal-title">Создание брони в Poster</div>
            <div class="res-modal-body">
                Вы собираетесь отправить эту бронь в Poster POS.<br><br>
                Это создаст официальную бронь на терминале официанта. Убедитесь, что все данные верны.
                <div id="vposterModalInfo" class="res-modal-info"></div>
            </div>
            <label class="res-modal-check">
                <input type="checkbox" id="vposterConfirmCheck">
                <span>проверил</span>
            </label>
            <div class="res-modal-actions">
                <button type="button" class="res-btn" id="vposterCancel">Отмена</button>
                <button type="button" class="res-btn primary" id="vposterOk" disabled>ОК</button>
            </div>
        </div>
    </div>

    <div class="res-hscroll" id="resHScroll" hidden><div class="res-hscroll-inner" id="resHScrollInner"></div></div>

    <script src="/assets/user_menu.js?v=20260417_1036"></script>
    <script src="/assets/js/reservations.js?v=20260417_1036"></script>
</body>
</html>
