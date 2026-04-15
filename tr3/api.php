<?php

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
      'samesite' => 'Lax'
    ]);
  }
}
if ($lang === null) {
  $cookieLang = strtolower(trim((string)($_COOKIE['links_lang'] ?? '')));
  if (in_array($cookieLang, $supportedLangs, true)) $lang = $cookieLang;
}
if ($lang === null) $lang = 'ru';
if (!isset($I18N[$lang])) $lang = 'ru';

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
    $vals = $metaRepo->getMany([$key, $soonKey]);
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

$ajax = (string)($_GET['ajax'] ?? '');

if ($ajax === 'bootstrap') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo json_encode([
    'ok' => true,
    'lang' => $lang,
    'locale' => ($lang === 'ru') ? 'ru-RU' : ($lang === 'vi' ? 'vi-VN' : 'en-US'),
    'str' => $I18N[$lang] ?? [],
    'i18n_all' => $I18N,
    'defaultResDateLocal' => $defaultResDateLocal,
    'allowedTableNums' => $allowedSchemeNums,
    'tableCapsByNum' => $tableCapsByNum,
    'soonBookingHours' => $soonBookingHours,
    'apiBase' => '/tr3/api.php',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($ajax === 'log_js') {
  header('Content-Type: application/json; charset=utf-8');
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  if (is_array($j)) {
    $logFile = __DIR__ . '/../js_debug.log';
    $entry = date('Y-m-d H:i:s') . ' | IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' | MSG: ' . ($j['msg'] ?? '') . ' | DATA: ' . json_encode($j['data'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
  }
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

$_GET['ajax'] = $ajax;
require __DIR__ . '/../Tr2.php';
exit;
