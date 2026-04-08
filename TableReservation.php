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

$supportedLangs = ['ru', 'en', 'vi'];
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
if ($lang === null) {
  $accept = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
  $parts = preg_split('/\s*,\s*/', $accept);
  foreach ($parts as $part) {
    if ($part === '') continue;
    $code = strtolower(trim(explode(';', $part, 2)[0]));
    $base = explode('-', $code, 2)[0];
    if (in_array($base, $supportedLangs, true)) { $lang = $base; break; }
  }
}
if ($lang === null) $lang = 'ru';

$I18N = [
  'ru' => [
    'page_title' => 'Схема бронирования',
    'data_on' => 'Данные на',
    'pick_date' => 'Выбрать дату',
    'comment_placeholder' => 'Пожелания, особые условия…',
    'booking_note' => 'Бронь держится 30 мин с момента старта. Если гость не пришел через 30 мин после начала — бронь аннулируется.',
    'zoom' => 'Масштаб',
    'musicians' => 'Музыканты',
    'cashier' => 'Касса',
    'bar' => 'BAR',
    'ok' => 'Ок',
    'cancel' => 'Отмена',
    'close' => 'Закрыть',
    'send' => 'Отправить',
    'confirm' => 'Подтвердите',
    'yes' => 'Да',
    'no' => 'Нет',
    'booking_request' => 'Заявка на бронь на столик',
    'your_name' => 'Ваше имя',
    'your_phone' => 'Ваш номер телефона',
    'comment' => 'Комментарий',
    'guests_count' => 'Кол-во гостей',
    'start_time' => 'Время старта брони',
    'messenger' => 'ВАШ МЕССЕНДЖЕР',
    'link_tg_hint' => 'Мессенджер обязателен',
    'preorder_title' => 'Предзаказ',
    'preorder_title_mobile' => 'Предзаказ. Нажмите кнопку меню ниже',
    'preorder_required' => 'Предзаказ обязателен для компаний больше 5 гостей.',
    'menu_btn' => 'Меню',
    'menu_loading' => 'Загрузка меню…',
    'menu_unavailable' => 'Меню недоступно.',
    'menu_load_failed' => 'Не удалось загрузить меню.',
    'checking' => 'Проверяю…',
    'press_ok' => 'Нажми “Ок”, чтобы проверить столики.',
    'press_ok_then_tables' => 'Выбери дату и нажми “Ок”. Потом кликай по столам.',
    'select_date_time' => 'Выбери дату и время',
    'try_ok_again' => 'Ошибка запроса. Попробуй нажать “Ок” ещё раз.',
    'confirm_capacity' => 'Вы хотите забронировать столик для {max} для {guests} гостей?',
    'busy_now' => 'занят\nсейчас',
    'dtp_title' => 'Выбор даты и времени',
    'status_free' => 'Свободен',
    'status_busy' => 'Занят',
    'tg_thanks_title' => 'Спасибо!',
    'tg_thanks_body' => 'Мы с вами свяжемся в ближайшее время.',
    'tg_booking_title' => 'Ваша бронь',
    'tg_date' => 'Дата',
    'tg_time' => 'Время',
    'tg_guests' => 'Кол-во человек',
    'tg_table' => 'Номер стола',
    'tg_name' => 'Имя',
    'tg_phone' => 'Номер телефона',
    'tg_comment' => 'Комментарий',
    'tg_preorder' => 'Предзаказ',
    'err_prefix' => 'Ошибка: ',
    'err_generic' => 'Ошибка',
    'err_no_bot_link' => 'Нет ссылки на бота',
    'hint_pick_table_first' => 'Сначала выбери столик.',
    'hint_opening_tg' => 'Открываю Telegram…',
    'hint_tg_back' => 'В Telegram нажми “Вернуться на сайт”.',
    'missing_prefix' => 'Не хватает: ',
    'missing_table' => 'выбери стол',
    'missing_start' => 'время старта',
    'missing_guests' => 'кол-во гостей',
    'missing_name' => 'имя',
    'missing_phone' => 'телефон',
    'missing_preorder' => 'предзаказ',
    'missing_telegram' => 'Telegram (привязать)',
    'sending' => 'Отправляю…',
    'submit_success' => 'Спасибо, мы с вами свяжемся в ближайшее время.\n\nСтарт: {start}\nСтол: {table}\nГостей: {guests}\nИмя: {name}\nТелефон: {phone}',
    'cap_warn' => 'Мы подставим вам стул, но вам может быть тесно за этим столиком :)',
  ],
  'en' => [
    'page_title' => 'Booking Map',
    'data_on' => 'Data for',
    'pick_date' => 'Pick date',
    'comment_placeholder' => 'Wishes, special conditions…',
    'booking_note' => 'Hold time is 30 minutes from start. If guest is late more than 30 minutes — reservation is cancelled.',
    'zoom' => 'Zoom',
    'musicians' => 'Musicians',
    'cashier' => 'Cashier',
    'bar' => 'BAR',
    'ok' => 'OK',
    'cancel' => 'Cancel',
    'close' => 'Close',
    'send' => 'Send',
    'confirm' => 'Confirm',
    'yes' => 'Yes',
    'no' => 'No',
    'booking_request' => 'Booking request for table',
    'your_name' => 'Your name',
    'your_phone' => 'Your phone',
    'comment' => 'Comment',
    'guests_count' => 'Guests',
    'start_time' => 'Start time',
    'messenger' => 'YOUR MESSENGER',
    'link_tg_hint' => 'Messenger is required',
    'preorder_title' => 'Pre-order',
    'preorder_title_mobile' => 'Pre-order. Click the menu button below',
    'preorder_required' => 'Pre-order is required for parties over 5 guests.',
    'menu_btn' => 'Menu',
    'menu_loading' => 'Loading menu…',
    'menu_unavailable' => 'Menu unavailable.',
    'menu_load_failed' => 'Failed to load menu.',
    'checking' => 'Checking…',
    'press_ok' => 'Press “OK” to check availability.',
    'press_ok_then_tables' => 'Pick a date and press “OK”. Then tap tables.',
    'select_date_time' => 'Select date and time',
    'try_ok_again' => 'Request failed. Please press “OK” again.',
    'confirm_capacity' => 'Do you want to book a table for {max} when you have {guests} guests?',
    'busy_now' => 'busy\nnow',
    'dtp_title' => 'Pick date & time',
    'status_free' => 'Free',
    'status_busy' => 'Busy',
    'tg_thanks_title' => 'Thank you!',
    'tg_thanks_body' => 'We will contact you shortly.',
    'tg_booking_title' => 'Your reservation',
    'tg_date' => 'Date',
    'tg_time' => 'Time',
    'tg_guests' => 'Guests',
    'tg_table' => 'Table',
    'tg_name' => 'Name',
    'tg_phone' => 'Phone',
    'tg_comment' => 'Comment',
    'tg_preorder' => 'Pre-order',
    'err_prefix' => 'Error: ',
    'err_generic' => 'Error',
    'err_no_bot_link' => 'Bot link is missing',
    'hint_pick_table_first' => 'Pick a table first.',
    'hint_opening_tg' => 'Opening Telegram…',
    'hint_tg_back' => 'In Telegram press “Back to site”.',
    'missing_prefix' => 'Missing: ',
    'missing_table' => 'pick table',
    'missing_start' => 'start time',
    'missing_guests' => 'guests',
    'missing_name' => 'name',
    'missing_phone' => 'phone',
    'missing_preorder' => 'pre-order',
    'missing_telegram' => 'Telegram (link)',
    'sending' => 'Sending…',
    'submit_success' => 'Thank you, we will contact you shortly.\n\nStart: {start}\nTable: {table}\nGuests: {guests}\nName: {name}\nPhone: {phone}',
    'cap_warn' => 'We can add an extra chair, but it may be tight at this table :)',
  ],
  'vi' => [
    'page_title' => 'Sơ đồ đặt bàn',
    'data_on' => 'Dữ liệu ngày',
    'pick_date' => 'Chọn ngày',
    'comment_placeholder' => 'Yêu cầu, ghi chú…',
    'booking_note' => 'Giữ bàn 30 phút từ lúc bắt đầu. Nếu khách đến muộn quá 30 phút — đặt bàn sẽ bị hủy.',
    'zoom' => 'Thu phóng',
    'musicians' => 'Nhạc',
    'cashier' => 'Thu ngân',
    'bar' => 'BAR',
    'ok' => 'OK',
    'cancel' => 'Hủy',
    'close' => 'Đóng',
    'send' => 'Gửi',
    'confirm' => 'Xác nhận',
    'yes' => 'Có',
    'no' => 'Không',
    'booking_request' => 'Yêu cầu đặt bàn',
    'your_name' => 'Tên của bạn',
    'your_phone' => 'Số điện thoại',
    'comment' => 'Ghi chú',
    'guests_count' => 'Số khách',
    'start_time' => 'Giờ bắt đầu',
    'messenger' => 'MESSENGER CỦA BẠN',
    'link_tg_hint' => 'Cần messenger',
    'preorder_title' => 'Đặt trước',
    'preorder_title_mobile' => 'Đặt trước. Nhấp vào nút menu bên dưới',
    'preorder_required' => 'Bắt buộc đặt trước cho nhóm trên 5 khách.',
    'menu_btn' => 'Menu',
    'menu_loading' => 'Đang tải menu…',
    'menu_unavailable' => 'Không có menu.',
    'menu_load_failed' => 'Không tải được menu.',
    'checking' => 'Đang kiểm tra…',
    'press_ok' => 'Nhấn “OK” để kiểm tra bàn trống.',
    'press_ok_then_tables' => 'Chọn ngày và nhấn “OK”. Sau đó chạm vào bàn.',
    'select_date_time' => 'Chọn ngày và giờ',
    'try_ok_again' => 'Lỗi yêu cầu. Hãy nhấn “OK” lại.',
    'confirm_capacity' => 'Bạn muốn đặt bàn {max} chỗ cho {guests} khách phải không?',
    'busy_now' => 'bận\nhiện tại',
    'dtp_title' => 'Chọn ngày & giờ',
    'status_free' => 'Trống',
    'status_busy' => 'Bận',
    'tg_thanks_title' => 'Cảm ơn!',
    'tg_thanks_body' => 'Chúng tôi sẽ liên hệ với bạn sớm.',
    'tg_booking_title' => 'Đặt bàn của bạn',
    'tg_date' => 'Ngày',
    'tg_time' => 'Giờ',
    'tg_guests' => 'Số khách',
    'tg_table' => 'Bàn',
    'tg_name' => 'Tên',
    'tg_phone' => 'Số điện thoại',
    'tg_comment' => 'Ghi chú',
    'tg_preorder' => 'Đặt trước',
    'err_prefix' => 'Lỗi: ',
    'err_generic' => 'Lỗi',
    'err_no_bot_link' => 'Không có link bot',
    'hint_pick_table_first' => 'Hãy chọn bàn trước.',
    'hint_opening_tg' => 'Đang mở Telegram…',
    'hint_tg_back' => 'Trong Telegram nhấn “Quay lại trang”.',
    'missing_prefix' => 'Thiếu: ',
    'missing_table' => 'chọn bàn',
    'missing_start' => 'giờ bắt đầu',
    'missing_guests' => 'số khách',
    'missing_name' => 'tên',
    'missing_phone' => 'số điện thoại',
    'missing_preorder' => 'đặt trước',
    'missing_telegram' => 'Telegram (liên kết)',
    'sending' => 'Đang gửi…',
    'submit_success' => 'Cảm ơn, chúng tôi sẽ liên hệ sớm.\n\nBắt đầu: {start}\nBàn: {table}\nSố khách: {guests}\nTên: {name}\nSĐT: {phone}',
    'cap_warn' => 'Chúng tôi có thể thêm ghế, nhưng bàn này có thể hơi chật :)',
  ],
];
if (!isset($I18N[$lang])) $lang = 'ru';
function tr(string $key): string {
  global $I18N, $lang;
  return isset($I18N[$lang][$key]) ? (string)$I18N[$lang][$key] : $key;
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

  $langIn = strtolower(trim((string)($payload['lang'] ?? '')));
  $userLang = in_array($langIn, ['ru', 'en', 'vi'], true) ? $langIn : $lang;
  $trFor = function (string $key) use ($I18N, $userLang): string {
    return isset($I18N[$userLang][$key]) ? (string)$I18N[$userLang][$key] : $key;
  };

  $tableNum = trim((string)($payload['table_num'] ?? ''));
  $name = trim((string)($payload['name'] ?? ''));
  $phone = trim((string)($payload['phone'] ?? ''));
  $comment = trim((string)($payload['comment'] ?? ''));
  $preorder = trim((string)($payload['preorder'] ?? ''));
  $preorderRu = trim((string)($payload['preorder_ru'] ?? ''));
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
  $comment = str_replace(["\r\n", "\r"], "\n", $comment);
  if (mb_strlen($comment) > 600) $comment = mb_substr($comment, 0, 600);
  $preorder = str_replace(["\r\n", "\r"], "\n", $preorder);
  if (mb_strlen($preorder) > 1200) $preorder = mb_substr($preorder, 0, 1200);
  $preorderRu = str_replace(["\r\n", "\r"], "\n", $preorderRu);
  if (mb_strlen($preorderRu) > 1200) $preorderRu = mb_substr($preorderRu, 0, 1200);
  if ($guests > 5 && trim($preorder) === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $trFor('preorder_required')], JSON_UNESCAPED_UNICODE);
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
  if ($comment !== '') {
    $text .= "\n";
    $text .= '<b>Комментарий:</b>' . "\n" . htmlspecialchars($comment);
  }
  $preForGroup = $preorderRu !== '' ? $preorderRu : $preorder;
  if ($preForGroup !== '') {
    $text .= "\n";
    $text .= '<b>Предзаказ:</b>' . "\n" . htmlspecialchars($preForGroup);
  }
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
  $userText = '<b>' . htmlspecialchars($trFor('tg_thanks_title')) . '</b> ' . htmlspecialchars($trFor('tg_thanks_body')) . "\n\n";
  $userText .= '<b>' . htmlspecialchars($trFor('tg_booking_title')) . '</b>' . "\n";
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

if (($_GET['ajax'] ?? '') === 'menu_preorder') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  if (!isset($db) || !($db instanceof \App\Classes\Database)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB not configured'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $supportedLangs = ['ru', 'en', 'vi', 'ko'];
  $lang = strtolower(trim((string)($_GET['lang'] ?? 'ru')));
  if (!in_array($lang, $supportedLangs, true)) $lang = 'ru';
  $trLang = $lang === 'vi' ? 'vn' : $lang;

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

  echo json_encode(['ok' => true, 'lang' => $lang, 'last_sync_at' => $lastMenuSyncAt, 'groups' => $out], JSON_UNESCAPED_UNICODE);
  exit;
}

?><!doctype html>
<html lang="<?= htmlspecialchars($lang) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(tr('page_title')) ?></title>
  <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
  <link rel="preconnect" href="https://api.fontshare.com">
  <link rel="preconnect" href="https://cdn.fontshare.com" crossorigin>
  <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700&f[]=clash-display@500,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/links/table-reservation.css?v=20260408_0021">

</head>
<body>
  <div class="app">
    <main class="panel">
      <div class="topbar">
        <div class="title-wrap">
          <h1 data-i18n="page_title"><?= htmlspecialchars(tr('page_title')) ?></h1>
          <p><span id="busyDateLabel" data-i18n="data_on"><?= htmlspecialchars(tr('data_on')) ?></span> <button type="button" class="dt-btn attn" id="resDateBtn" data-i18n="pick_date"><?= htmlspecialchars(tr('pick_date')) ?></button><span class="mini-loader" id="busyDateLoader" hidden></span></p>
          <input type="datetime-local" id="resDate" aria-label="<?= htmlspecialchars(tr('select_date_time')) ?>">
        </div>
        <div class="busy-progress" id="busyProgress" hidden></div>
        <div class="controls">
          <label class="zoom" aria-label="<?= htmlspecialchars(tr('zoom')) ?>">
            <span data-i18n="zoom"><?= htmlspecialchars(tr('zoom')) ?></span>
            <button class="zbtn" type="button" id="mapZoomMinus" aria-label="−">−</button>
            <span class="zv" id="mapZoomVal">100%</span>
            <button class="zbtn" type="button" id="mapZoomPlus" aria-label="+">+</button>
            <input id="mapZoomRange" type="range" min="10" max="100" step="1" value="100" aria-label="<?= htmlspecialchars(tr('zoom')) ?>">
          </label>
        </div>
        <?php
          $self = strtok((string)($_SERVER['REQUEST_URI'] ?? '/TableReservation.php'), '?');
          $params = $_GET;
          unset($params['ajax'], $params['lang']);
          $baseQs = http_build_query($params);
          $mk = function (string $l) use ($self, $baseQs) {
            $qs = $baseQs !== '' ? ($baseQs . '&lang=' . rawurlencode($l)) : ('lang=' . rawurlencode($l));
            return $self . '?' . $qs;
          };
        ?>
        <div class="lang" aria-label="Language">
          <a href="<?= htmlspecialchars($mk('ru')) ?>" class="<?= $lang === 'ru' ? 'active' : '' ?>">RU</a>
          <a href="<?= htmlspecialchars($mk('en')) ?>" class="<?= $lang === 'en' ? 'active' : '' ?>">EN</a>
          <a href="<?= htmlspecialchars($mk('vi')) ?>" class="<?= $lang === 'vi' ? 'active' : '' ?>">VI</a>
        </div>
      </div>
  
      <section class="layout">
        <div class="map-shell">
          <div class="tile-layer" aria-hidden="true"></div>
            <div class="map-zoom-box" id="mapZoomBox">
              <div class="map-zoom-inner" id="mapZoomInner">
              <div class="map" aria-label="Схема столов ресторана">
            <div class="grass-corner-1-7" aria-hidden="true"></div>
            <button class="table large" style="left: 712px; top: 276px;" data-table="1"><span class="num">1</span><span class="cap"></span></button>
            <button class="table large" style="left: 712px; top: 402px;" data-table="2"><span class="num">2</span><span class="cap"></span></button>
            <button class="table large" style="left: 712px; top: 528px;" data-table="3"><span class="num">3</span><span class="cap"></span></button>
  
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
                <path class="water-fall" d="M32 18c0 10-6 12-6 20" stroke="url(#fWat)" stroke-width="3" stroke-linecap="round"/>
                <path class="water-fall" d="M32 18c0 10 6 12 6 20" stroke="url(#fWat)" stroke-width="3" stroke-linecap="round"/>
                <path class="water-fall-center" d="M32 14c0 10 0 14 0 24" stroke="rgba(255,255,255,0.78)" stroke-width="3" stroke-linecap="round"/>
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
                <div class="side-station" data-i18n="musicians"><?= htmlspecialchars(tr('musicians')) ?></div>
              </div>
              <div class="bar" data-i18n="bar"><?= htmlspecialchars(tr('bar')) ?></div>
              <div class="station-wrap cash">
                <div class="side-station" data-i18n="cashier"><?= htmlspecialchars(tr('cashier')) ?></div>
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
      <div class="dtp-title" id="dtpTitle" data-i18n="dtp_title"><?= htmlspecialchars(tr('dtp_title')) ?></div>
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
        <button class="btn btn-secondary" type="button" data-dtp-close data-i18n="cancel"><?= htmlspecialchars(tr('cancel')) ?></button>
        <button class="btn btn-primary" type="button" id="dtpOk" data-i18n="ok"><?= htmlspecialchars(tr('ok')) ?></button>
      </div>
    </div>
  </div>

  <div class="modal" id="capModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close="capModal"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="capModalTitle">
      <div class="modal-title" id="capModalTitle" data-i18n="confirm"><?= htmlspecialchars(tr('confirm')) ?></div>
      <div class="modal-text" id="capModalText"></div>
      <div class="modal-actions">
        <button class="btn btn-secondary" type="button" id="capModalNo" data-i18n="no"><?= htmlspecialchars(tr('no')) ?></button>
        <button class="btn btn-primary" type="button" id="capModalYes" data-i18n="yes"><?= htmlspecialchars(tr('yes')) ?></button>
      </div>
    </div>
  </div>

  <div class="modal" id="reqModal" aria-hidden="true">
    <div class="modal-backdrop" data-modal-close="reqModal"></div>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="reqModalTitle" id="reqModalCard">
      <div class="modal-title-bar">
        <div class="modal-title" id="reqModalTitle"><span data-i18n="booking_request"><?= htmlspecialchars(tr('booking_request')) ?></span> <span id="reqModalTable"></span></div>
        <button class="btn-close-modal" type="button" data-modal-close="reqModal" aria-label="Close">×</button>
      </div>
      <form id="reqForm">
        <div class="req-layout">
          <div class="req-left" id="reqLeft">
            <div class="modal-grid">
              <label class="modal-label">
                <span data-i18n="your_name"><?= htmlspecialchars(tr('your_name')) ?></span>
                <input type="text" id="reqName" autocomplete="name">
              </label>
              <label class="modal-label">
                <div class="label-row">
                  <span data-i18n="your_phone"><?= htmlspecialchars(tr('your_phone')) ?></span>
                  <div class="msgr-hint" id="msgrHint" hidden></div>
                </div>
                <div class="phone-row">
                  <input type="tel" id="reqPhone" autocomplete="tel">
                  <button type="button" class="msgr-btn msgr-btn-inline" id="msgrTgBtn" aria-label="Telegram" title="Telegram">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                      <path d="M20.6 5.3 4.2 11.7c-1.1.4-1.1 1-.2 1.3l4.2 1.3 1.6 4.8c.2.6.4.6.8.2l2.3-2.2 4.7 3.4c.9.5 1.5.2 1.7-.8l2.8-13.1c.3-1.2-.4-1.7-1.5-1.3Z" fill="currentColor" opacity=".9"/>
                      <path d="M9.1 14.9 18.3 8.9c.5-.3.9-.1.5.2l-7.6 6.9-.3 2.9c0 .4-.2.5-.4.1l-1.5-4.8Z" fill="currentColor"/>
                    </svg>
                  </button>
                </div>
              </label>
              <label class="modal-label full" id="reqCommentLabel">
                <span data-i18n="comment"><?= htmlspecialchars(tr('comment')) ?></span>
                <textarea id="reqComment" class="preorder-box" rows="4" placeholder="<?= htmlspecialchars(tr('comment_placeholder')) ?>"></textarea>
              </label>
              <label class="modal-label full" id="reqPreorderLabel" hidden>
                <span class="desktop-text" data-i18n="preorder_title"><?= htmlspecialchars(tr('preorder_title')) ?></span>
                <span class="mobile-text" data-i18n="preorder_title_mobile"><?= htmlspecialchars(tr('preorder_title_mobile')) ?></span>
                <div id="reqPreorderBox" class="preorder-box" aria-readonly="true"></div>
              </label>
              <div class="guests-time-row full">
                <label class="modal-label">
                  <span data-i18n="guests_count"><?= htmlspecialchars(tr('guests_count')) ?></span>
                  <div class="num-step">
                    <button class="num-btn" type="button" id="reqGuestsMinus" aria-label="Уменьшить кол-во гостей">−</button>
                    <input type="number" id="reqGuests" min="1" max="99">
                    <button class="num-btn" type="button" id="reqGuestsPlus" aria-label="Увеличить кол-во гостей">+</button>
                    <button type="button" class="btn btn-secondary btn-preorder-mobile" id="btnOpenMobilePreorder" hidden aria-label="Menu">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                      <span data-i18n="menu_btn"><?= htmlspecialchars(tr('menu_btn')) ?></span>
                    </button>
                  </div>
                </label>
                <label class="modal-label">
                  <span data-i18n="start_time"><?= htmlspecialchars(tr('start_time')) ?></span>
                  <input type="text" id="reqStart" readonly>
                </label>
              </div>
            </div>
          </div>
          <div class="req-right" id="preorderPanel" hidden>
            <div class="pre-title" data-i18n="preorder_title"><?= htmlspecialchars(tr('preorder_title')) ?></div>
            <div class="pre-body" id="preorderBody"></div>
          </div>
        </div>
        <div class="modal-hint" id="reqHint" hidden></div>
        <div class="modal-hint preorder" id="preorderReqHint" hidden data-i18n="preorder_required"><?= htmlspecialchars(tr('preorder_required')) ?></div>
        <div class="modal-note" data-i18n="booking_note"><?= htmlspecialchars(tr('booking_note')) ?></div>
        <div class="modal-actions">
          <button class="btn btn-primary" type="submit" id="reqSubmit" data-i18n="send"><?= htmlspecialchars(tr('send')) ?></button>
        </div>
      </form>
    </div>
  </div>

  <div class="table-toast" id="tableToast" aria-live="polite" aria-atomic="true">
    <div class="t-title" id="toastTitle"></div>
    <div class="t-reason" id="toastReason"></div>
  </div>

  <div class="modal" id="mobilePreorderModal" aria-hidden="true">
    <div class="modal-backdrop modal-backdrop-strong" data-modal-close="mobilePreorderModal"></div>
    <div class="modal-card preorder-modal-card" role="dialog" aria-modal="true" aria-labelledby="mobilePreorderTitle">
      <div class="modal-title-bar">
        <div class="modal-title-left">
          <div class="modal-title" id="mobilePreorderTitle" data-i18n="preorder_title"><?= htmlspecialchars(tr('preorder_title')) ?></div>
          <div class="modal-total" id="mobilePreorderTotal"></div>
        </div>
        <button class="btn-close-modal" type="button" data-modal-close="mobilePreorderModal" aria-label="Close">×</button>
      </div>
      <div class="mobile-preorder-layout">
        <div class="preorder-top">
          <div id="mobilePreorderBox" class="preorder-box"></div>
        </div>
        <div class="preorder-bottom">
          <div id="mobilePreorderMenuBody" class="pre-body"></div>
        </div>
      </div>
    </div>
  </div>
  
    <script>
    window.__TR_CONFIG__ = {
      lang: <?= json_encode($lang, JSON_UNESCAPED_UNICODE) ?>,
      locale: <?= json_encode($lang === 'ru' ? 'ru-RU' : ($lang === 'vi' ? 'vi-VN' : 'en-US'), JSON_UNESCAPED_UNICODE) ?>,
      str: <?= json_encode($I18N[$lang], JSON_UNESCAPED_UNICODE) ?>,
      i18n_all: <?= json_encode($I18N, JSON_UNESCAPED_UNICODE) ?>,
      defaultResDateLocal: <?= json_encode($defaultResDateLocal, JSON_UNESCAPED_UNICODE) ?>,
      allowedTableNums: <?= json_encode($allowedSchemeNums, JSON_UNESCAPED_UNICODE) ?>,
      tableCapsByNum: <?= json_encode($tableCapsByNum, JSON_UNESCAPED_UNICODE) ?>,
    };
  </script>
  <script src="/links/table-reservation.js?v=20260408_0021"></script>
</body>
</html>
