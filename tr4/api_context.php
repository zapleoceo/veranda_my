<?php
declare(strict_types=1);

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
      'samesite' => 'Lax',
    ]);
  }
}
if ($lang === null) {
  $cookieLang = strtolower(trim((string)($_COOKIE['links_lang'] ?? '')));
  if (in_array($cookieLang, $supportedLangs, true)) $lang = $cookieLang;
}
if ($lang === null) $lang = 'ru';
if (!isset($I18N[$lang])) $lang = 'ru';

$GLOBALS['I18N'] = $I18N;
$GLOBALS['lang'] = $lang;

if (!function_exists('tr')) {
  function tr(string $key): string {
    $I18N = $GLOBALS['I18N'] ?? [];
    $lang = $GLOBALS['lang'] ?? 'ru';
    return (is_array($I18N) && isset($I18N[$lang][$key])) ? (string)$I18N[$lang][$key] : $key;
  }
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

if (!function_exists('wa_bridge_send')) {
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
}

if (!function_exists('wa_bridge_is_available')) {
  function wa_bridge_is_available(): bool {
    $host = trim((string)($_ENV['WA_HTTP_HOST'] ?? '127.0.0.1'));
    $portRaw = trim((string)($_ENV['WA_HTTP_PORT'] ?? '3210'));
    $port = is_numeric($portRaw) ? (int)$portRaw : 3210;
    if ($port <= 0 || $port > 65535) $port = 3210;

    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.4);
    if ($fp === false) return false;
    fclose($fp);
    return true;
  }
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

$db = null;
$metaRepo = null;
$latestWorkday = '21:00';
$latestWeekend = '22:00';

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

    $latestWorkday = array_key_exists($workdayKey, $vals) ? trim((string)$vals[$workdayKey]) : $latestWorkday;
    $latestWeekend = array_key_exists($weekendKey, $vals) ? trim((string)$vals[$weekendKey]) : $latestWeekend;
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

return [
  'cfg' => $cfg,
  'supportedLangs' => $supportedLangs,
  'I18N' => $I18N,
  'lang' => $lang,
  'displayTzName' => $displayTzName,
  'apiTzName' => $apiTzName,
  'posterToken' => $posterToken,
  'defaultResDateLocal' => $defaultResDateLocal,
  'allowedSchemeNums' => $allowedSchemeNums,
  'soonBookingHours' => $soonBookingHours,
  'tableCapsByNum' => $tableCapsByNum,
  'latestWorkday' => $latestWorkday,
  'latestWeekend' => $latestWeekend,
  'db' => $db,
  'metaRepo' => $metaRepo,
];
