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

$ajax = $_GET['ajax'] ?? '';
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

    if ($tgToken === '' || $tgChatId === '') {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Telegram not configured'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $startDt = new DateTimeImmutable($row['start_time']);
    
    // Group Message
    $text = '<b>[Повтор] Новая бронь с сайта</b>' . "\n";
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
    $tgUid = (int)$row['tg_user_id'];
    $tgUn = (string)$row['tg_username'];
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

    $bot = new \App\Classes\TelegramBot($tgToken, $tgChatId);
    $okGroup = $bot->sendMessage($text, $tgThreadNum > 0 ? $tgThreadNum : null);

    // Guest Message (localized to reservation language)
    $okGuest = true;
    if ($tgUid > 0) {
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
        $userText .= '<b>' . htmlspecialchars($tr('booking_title')) . '</b>' . "\n";
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
        $okGuest = false; // No TG linked
    }

    echo json_encode([
        'ok' => true,
        'group_ok' => $okGroup,
        'guest_ok' => $okGuest,
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
        echo json_encode([
            'ok' => true,
            'deleted' => $deletedAt !== '',
            'deleted_at' => $deletedAt !== '' ? date('d.m.Y H:i', strtotime($deletedAt)) : '',
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

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d', strtotime('-1 week'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d', strtotime('+1 month'));

$sort = $_GET['sort'] ?? 'start_time';
$order = strtolower($_GET['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$validSorts = ['id', 'created_at', 'start_time', 'table_num', 'guests', 'name', 'phone', 'total_amount'];
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
                <label class="res-switch">
                    <input id="showDeleted" type="checkbox" <?= $showDeleted ? 'checked' : '' ?>>
                    <span>Показывать удалённые</span>
                </label>
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
                <?php if ($showDeleted): ?><input type="hidden" name="show_deleted" value="1"><?php endif; ?>
                <button type="submit" class="btn-primary">Показать</button>
            </form>

            <div class="table-wrap">
                <table class="res-table">
                    <thead>
                        <tr>
                            <th data-sort="id">ID<?= $sort==='id'?($order==='asc'?' ↑':' ↓'):'' ?></th>
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
                            <tr><td colspan="9" style="text-align:center; padding:20px; color:var(--muted);">Нет броней за выбранный период</td></tr>
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
                                    $isDeleted = $deletedAt !== '' && $deletedAt !== null;
                                    $deletedAtHuman = $isDeleted ? date('d.m.Y H:i', strtotime($deletedAt)) : '';
                                ?>
                                <tr data-id="<?= (int)$r['id'] ?>" class="<?= $isDeleted ? 'is-deleted' : '' ?>">
                                    <td data-label="ID">
                                        <div><?= (int)$r['id'] ?></div>
                                        <div class="tag deleted" id="deleted-tag-<?= (int)$r['id'] ?>" <?= $isDeleted ? '' : 'hidden' ?>>Удалено<?= $deletedBy !== '' ? ' · ' . htmlspecialchars($deletedBy) : '' ?></div>
                                        <div class="res-muted" id="deleted-meta-<?= (int)$r['id'] ?>" <?= $isDeleted ? '' : 'hidden' ?>><?= htmlspecialchars($deletedAtHuman) ?></div>
                                    </td>
                                    <td data-label="Создано"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
                                    <td data-label="Время" class="res-strong"><?= htmlspecialchars(date('d.m.Y H:i', strtotime($r['start_time']))) ?></td>
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
                                        <div class="res-actions">
                                            <button type="button" class="res-btn primary btn-resend" data-id="<?= (int)$r['id'] ?>">Повторить</button>
                                            <button type="button" class="res-btn danger btn-delete" data-id="<?= (int)$r['id'] ?>"><?= $isDeleted ? 'Восстановить' : 'Удалить' ?></button>
                                        </div>
                                        <div class="res-status" id="resend-status-<?= (int)$r['id'] ?>"></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="/assets/user_menu.js?v=20260410_0410"></script>
    <script src="/assets/js/reservations.js?v=20260410_0510"></script>
</body>
</html>
