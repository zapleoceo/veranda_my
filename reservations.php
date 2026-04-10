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

$db = new \App\Classes\Database(
    $_ENV['DB_HOST'] ?? 'localhost',
    $_ENV['DB_NAME'] ?? 'veranda_my',
    $_ENV['DB_USER'] ?? 'veranda_my',
    $_ENV['DB_PASS'] ?? '',
    (string)($_ENV['DB_TABLE_SUFFIX'] ?? '')
);
$db->createReservationsTable();
$resTable = $db->t('reservations');

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

    try {
        $api = new \App\Classes\PosterAPI($_ENV['POSTER_API_TOKEN']);
        
        // Find table_id by table_num using spots.getTableHallTables
        $tableId = null;
        $allTables = $api->request('spots.getTableHallTables', ['spot_id' => 1, 'hall_id' => 2]);
        if (is_array($allTables)) {
            foreach ($allTables as $t) {
                if (trim((string)($t['table_num'] ?? '')) === trim((string)($row['table_num'] ?? ''))) {
                    $tableId = (int)$t['table_id'];
                    break;
                }
            }
        }

        if (!$tableId) {
            // Fallback: try spots.getTables if hall 2 mapping failed
            $allTablesFallback = $api->request('spots.getTables', ['spot_id' => 1]);
            if (is_array($allTablesFallback)) {
                foreach ($allTablesFallback as $t) {
                    if (trim((string)($t['table_num'] ?? '')) === trim((string)($row['table_num'] ?? ''))) {
                        $tableId = (int)$t['table_id'];
                        break;
                    }
                }
            }
        }

        if (!$tableId) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Не удалось найти ID стола в Poster для номера ' . $row['table_num']], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fullName = trim((string)$row['name']);
        $nameParts = explode(' ', $fullName, 2);
        $firstName = $nameParts[0] ?? 'Guest';
        $lastName = $nameParts[1] ?? '';

        $reservationData = [
            'spot_id' => 1,
            'type' => 2, // Reservation
            'table_id' => $tableId,
            'guests_count' => (int)$row['guests'],
            'date_reservation' => date('Y-m-d H:i:s', strtotime($row['start_time'])),
            'duration' => 7200, // 2 hours default
            'phone' => preg_replace('/\D+/', '', (string)$row['phone']),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'comment' => trim(($row['comment'] ?? '') . ' (Site #' . $row['id'] . ')')
        ];

        $resp = $api->request('incomingOrders.createReservation', $reservationData, 'POST');
        
        echo json_encode(['ok' => true, 'poster_res' => $resp], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Poster API error: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
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

    $startDt = new DateTimeImmutable($row['start_time']);
    
    // Group Message
    $tgUid = (int)$row['tg_user_id'];
    $tgUn = (string)$row['tg_username'];

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
        if ($tgUn !== '' || $tgUid > 0) {
            $text .= "\nTelegram: ";
            if ($tgUn !== '') {
                $text .= '<a href="https://t.me/' . htmlspecialchars($tgUn) . '">@' . htmlspecialchars($tgUn) . '</a>';
                if ($tgUid > 0) $text .= ' · <a href="tg://user?id=' . htmlspecialchars((string)$tgUid) . '">Открыть чат</a>';
            } elseif ($tgUid > 0) {
                $text .= '<a href="tg://user?id=' . htmlspecialchars((string)$tgUid) . '">Открыть чат</a> (id ' . htmlspecialchars((string)$tgUid) . ')';
            }
        }
        $text .= "\n\n@Ollushka90 @ce_akh1 свяжитесь с гостем";
        $okGroup = $bot->sendMessage($text, $tgThreadNum > 0 ? $tgThreadNum : null);
    }

    // Guest Message (localized to reservation language)
    $okGuest = true;
    if (($target === 'both' || $target === 'guest') && $tgUid > 0) {
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

        $userText = '<b>' . htmlspecialchars($tr('thanks_title')) . '</b> ' . htmlspecialchars($tr('thanks_body')) . "\n\n";
        $qrUrl = (string)$row['qr_url'];
        if ($qrUrl !== '') {
            $userText .= '<b>' . htmlspecialchars($tr('payment_title')) . "</b>\n";
            $userText .= htmlspecialchars($tr('payment_body')) . "\n\n";
            $userText .= '<a href="' . htmlspecialchars($qrUrl) . '">' . htmlspecialchars($tr('payment_link')) . '</a>' . "\n\n";
        }
        $userText .= '<b>' . htmlspecialchars($tr('booking_title')) . ' #' . htmlspecialchars($code) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('date')) . ': <b>' . htmlspecialchars($startDt->format('Y-m-d')) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('time')) . ': <b>' . htmlspecialchars($startDt->format('H:i')) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('guests')) . ': <b>' . htmlspecialchars((string)$row['guests']) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('table')) . ': <b>' . htmlspecialchars($row['table_num']) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('name')) . ': <b>' . htmlspecialchars($row['name']) . '</b>' . "\n";
        $userText .= htmlspecialchars($tr('phone')) . ': <b>' . htmlspecialchars($row['phone']) . '</b>';
        if ($row['comment'] !== '') {
            $userText .= "\n<b>" . htmlspecialchars($tr('comment')) . ":</b>\n" . htmlspecialchars($row['comment']);
        }
        if ($row['preorder_text'] !== '') {
            $userText .= "\n<b>" . htmlspecialchars($tr('preorder')) . ":</b>\n" . htmlspecialchars($row['preorder_text']);
        }

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
            $okGuest = false;
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
        'has_tg' => $tgUid > 0
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
        
        // Fetch all tables to map table_id to table_title
        $tableNameMap = [];
        try {
            // Using spots.getTableHallTables to get table_title as requested
            // Assuming hall_id 2 is the main hall as used in other parts of the project
            $allTables = $api->request('spots.getTableHallTables', ['spot_id' => 1, 'hall_id' => 2]);
            if (is_array($allTables)) {
                foreach ($allTables as $t) {
                    if (isset($t['table_id'])) {
                        // Use table_num as requested, fallback to table_title
                        $tableNameMap[(int)$t['table_id']] = $t['table_num'] ?? $t['table_title'] ?? $t['table_id'];
                    }
                }
            }
        } catch (\Throwable $e_tables) {
            // Table mapping failed, will use ID as fallback
        }

        $resp = $api->request('incomingOrders.getReservations', [
            'date_from' => $dateFrom . ' 00:00:00',
            'date_to' => $dateTo . ' 23:59:59',
        ], 'GET');
        
        if (is_array($resp)) {
            foreach ($resp as $pr) {
                $status = (int)($pr['status'] ?? 0);
                if (!$showDeleted && $status === 0) continue; // Skip cancelled if not showing deleted
                
                $tId = (int)($pr['table_id'] ?? 0);
                $displayTable = isset($tableNameMap[$tId]) ? $tableNameMap[$tId] : ($tId > 0 ? $tId : '?');

                $posterRows[] = [
                    'id' => 'P' . ($pr['incoming_order_id'] ?? 0),
                    'qr_code' => 'POSTER',
                    'created_at' => $pr['date_created'] ?? '',
                    'start_time' => $pr['date_reservation'] ?? '',
                    'table_num' => $displayTable,
                    'guests' => $pr['guests_count'] ?? 0,
                    'name' => trim(($pr['first_name'] ?? '') . ' ' . ($pr['last_name'] ?? '')),
                    'phone' => $pr['phone'] ?? '',
                    'comment' => $pr['comment'] ?? '',
                    'preorder_text' => '',
                    'preorder_ru' => '',
                    'total_amount' => 0,
                    'qr_url' => '',
                    'is_poster' => true,
                    'status_text' => ($status === 0 ? 'Отменено' : 'Активно'),
                    'deleted_at' => ($status === 0 ? ($pr['date_updated'] ?? '1') : null),
                ];
            }
        }
    } catch (\Throwable $e) {
        // Log error to PHP error log for debugging
        error_log("Poster Reservations Error: " . $e->getMessage());
    }
}

$allRows = array_merge($rows, $posterRows);
usort($allRows, function($a, $b) use ($sort, $order) {
    $valA = $a[$sort] ?? '';
    $valB = $b[$sort] ?? '';
    if ($order === 'asc') return $valA <=> $valB;
    return $valB <=> $valA;
});
$rows = $allRows;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Брони</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/css/reservations.css?v=20260410_0510">
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
                <div class="res-controls" style="display: flex; gap: 15px; align-items: center;">
                    <label class="res-switch">
                        <input id="showPoster" type="checkbox" <?= $showPoster ? 'checked' : '' ?>>
                        <span>Брони Poster</span>
                    </label>
                    <label class="res-switch">
                        <input id="showDeleted" type="checkbox" <?= $showDeleted ? 'checked' : '' ?>>
                        <span>Удалённые</span>
                    </label>
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

            <div class="table-wrap">
                <table class="res-table">
                    <thead>
                        <tr>
                            <th data-sort="id">ID<?= $sort==='id'?($order==='asc'?' ↑':' ↓'):'' ?></th>
                            <th data-sort="qr_code">Код<?= $sort==='qr_code'?($order==='asc'?' ↑':' ↓'):'' ?></th>
                            <th data-sort="created_at">Создано<?= $sort==='created_at'?($order==='asc'?' ↑':' ↓'):'' ?></th>
                            <th data-sort="start_time">Время брони<?= $sort==='start_time'?($order==='asc'?' ↑':' ↓'):'' ?></th>
                            <th data-sort="table_num">Стол<?= $sort==='table_num'?($order==='asc'?' ↑':' ↓'):'' ?></th>
                            <th data-sort="guests">Гостей<?= $sort==='guests'?($order==='asc'?' ↑':' ↓'):'' ?></th>
                            <th data-sort="name">Гость<?= $sort==='name'?($order==='asc'?' ↑':' ↓'):'' ?></th>
                            <th data-sort="total_amount">Сумма<?= $sort==='total_amount'?($order==='asc'?' ↑':' ↓'):'' ?></th>
                            <th>QR</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="10" style="text-align:center; padding:20px; color:var(--muted);">Нет броней за выбранный период</td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php
                                    // Parse TG username correctly if it exists
                                    $tgUsername = (string)($r['tg_username'] ?? '');
                                    $tgUsername = trim($tgUsername);
                                    if ($tgUsername !== '') {
                                        $tgUsername = ltrim($tgUsername, '@');
                                    }
                                    $deletedAt = (string)($r['deleted_at'] ?? '');
                                    $deletedBy = (string)($r['deleted_by'] ?? '');
                                    $isDeleted = $deletedAt !== '' && $deletedAt !== null && $deletedAt !== '0000-00-00 00:00:00';
                                    $deletedAtHuman = $isDeleted ? date('d.m.Y H:i', strtotime($deletedAt)) : '';
                                    $isPoster = !empty($r['is_poster']);
                                ?>
                                <tr data-id="<?= htmlspecialchars((string)$r['id']) ?>" class="<?= $isDeleted ? 'is-deleted' : '' ?> <?= $isPoster ? 'is-poster' : '' ?>">
                                    <td data-label="ID">
                                        <div>#<?= htmlspecialchars((string)$r['id']) ?></div>
                                        <?php if ($isPoster): ?>
                                            <div class="tag poster">POSTER</div>
                                        <?php endif; ?>
                                        <?php if ($isDeleted): ?>
                                            <div class="tag deleted" id="deleted-tag-<?= htmlspecialchars((string)$r['id']) ?>">
                                                <?= $isPoster ? ($r['status_text'] ?? 'Удалено') : 'Удалено' ?><?= $deletedBy !== '' ? ' · ' . htmlspecialchars($deletedBy) : '' ?>
                                            </div>
                                            <div class="res-muted" id="deleted-meta-<?= htmlspecialchars((string)$r['id']) ?>">
                                                <?= htmlspecialchars($deletedAtHuman) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Код">
                                        <?php if (!empty($r['qr_code'])): ?>
                                            <span class="tag"><?= htmlspecialchars($r['qr_code']) ?></span>
                                        <?php else: ?>
                                            <span class="res-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Создано"><?= !empty($r['created_at']) ? htmlspecialchars(date('d.m.Y H:i', strtotime($r['created_at']))) : '—' ?></td>
                                    <td data-label="Время" class="res-strong"><?= !empty($r['start_time']) ? htmlspecialchars(date('d.m.Y H:i', strtotime($r['start_time']))) : '—' ?></td>
                                    <td data-label="Стол"><?= htmlspecialchars($r['table_num']) ?></td>
                                    <td data-label="Гостей"><?= (int)$r['guests'] ?></td>
                                    <td data-label="Гость">
                                        <div style="font-weight:900;"><?= htmlspecialchars($r['name']) ?></div>
                                        <div class="res-muted"><?= htmlspecialchars($r['phone']) ?></div>
                                        <?php if ($tgUsername !== ''): ?>
                                            <div class="res-muted"><a href="https://t.me/<?= htmlspecialchars($tgUsername) ?>" target="_blank" style="color:var(--accent); text-decoration:none;">@<?= htmlspecialchars($tgUsername) ?></a></div>
                                        <?php endif; ?>
                                        <?php if (!empty($r['comment']) || !empty($r['preorder_text'])): ?>
                                            <details class="res-more">
                                                <summary>Комментарий / предзаказ</summary>
                                                <div class="box">
                                                    <?php if (!empty($r['comment'])): ?>
                                                        <div><b>Комментарий:</b> <?= htmlspecialchars($r['comment']) ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($r['preorder_text'])): ?>
                                                        <div style="margin-top:6px;"><b>Предзаказ:</b><div class="pre"><?= htmlspecialchars($r['preorder_text']) ?></div></div>
                                                    <?php endif; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Сумма"><?= $r['total_amount'] > 0 ? number_format($r['total_amount'], 0, '.', ' ') . ' ₫' : '—' ?></td>
                                    <td data-label="QR">
                                        <?php if (!empty($r['qr_url'])): ?>
                                            <a href="<?= htmlspecialchars($r['qr_url']) ?>" target="_blank" style="color:var(--accent); font-weight:900; text-decoration:none;">QR</a>
                                        <?php else: ?>
                                            <span class="res-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Действия">
                                        <?php if (!$isPoster): ?>
                                            <div class="res-actions">
                                                <button type="button" class="res-btn primary btn-resend" data-id="<?= htmlspecialchars((string)$r['id']) ?>" data-target="guest">ReGuest</button>
                                                <button type="button" class="res-btn primary btn-resend" data-id="<?= htmlspecialchars((string)$r['id']) ?>" data-target="manager">ReManager</button>
                                                <?php if ($hasPosterAccess): ?>
                                                    <button type="button" class="res-btn primary btn-vposter" data-id="<?= htmlspecialchars((string)$r['id']) ?>">вPoster</button>
                                                <?php endif; ?>
                                                <button type="button" class="res-btn danger btn-delete" data-id="<?= htmlspecialchars((string)$r['id']) ?>"><?= $isDeleted ? 'Восстановить' : 'Удалить' ?></button>
                                            </div>
                                            <div class="res-status" id="resend-status-<?= htmlspecialchars((string)$r['id']) ?>"></div>
                                        <?php else: ?>
                                            <span class="res-muted">Управление в Poster POS</span>
                                        <?php endif; ?>
                                    </td>
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

    <script src="/assets/user_menu.js?v=20260410_0410"></script>
    <script src="/assets/js/reservations.js?v=20260410_0715"></script>
</body>
</html>
