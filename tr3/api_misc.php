<?php
declare(strict_types=1);

function tr3_api_bootstrap(array $ctx): void {
  api_json_headers(true);

  $minPreorderPerGuest = 100000;
  $metaRepo = $ctx['metaRepo'] ?? null;
  try {
    if ($metaRepo instanceof \App\Classes\MetaRepository) {
      $vals = $metaRepo->getMany(['preorder_min_per_guest_vnd']);
      $stored = array_key_exists('preorder_min_per_guest_vnd', $vals) ? trim((string)$vals['preorder_min_per_guest_vnd']) : '';
      if ($stored !== '' && is_numeric($stored)) $minPreorderPerGuest = max(0, (int)$stored);
    }
  } catch (\Throwable $e) {}

  api_ok([
    'lang' => (string)($ctx['lang'] ?? 'ru'),
    'locale' => ((string)($ctx['lang'] ?? 'ru') === 'ru') ? 'ru-RU' : (((string)($ctx['lang'] ?? 'ru') === 'vi') ? 'vi-VN' : 'en-US'),
    'str' => $ctx['I18N'][(string)($ctx['lang'] ?? 'ru')] ?? [],
    'i18n_all' => $ctx['I18N'] ?? [],
    'defaultResDateLocal' => $ctx['defaultResDateLocal'] ?? null,
    'allowedTableNums' => $ctx['allowedSchemeNums'] ?? null,
    'tableCapsByNum' => $ctx['tableCapsByNum'] ?? [],
    'soonBookingHours' => $ctx['soonBookingHours'] ?? 0,
    'latestWorkday' => $ctx['latestWorkday'] ?? '21:00',
    'latestWeekend' => $ctx['latestWeekend'] ?? '22:00',
    'minPreorderPerGuest' => $minPreorderPerGuest,
    'apiBase' => '/tr3/api.php',
  ]);
}

function tr3_api_log_js(array $ctx): void {
  api_json_headers(false);
  $dbg = trim((string)($_ENV['DEBUG'] ?? $_ENV['TR3_DEBUG'] ?? ''));
  if ($dbg !== '1') api_ok();

  $j = api_read_payload();
  if (is_array($j)) {
    $msg = trim((string)($j['msg'] ?? ''));
    if (mb_strlen($msg) > 800) $msg = mb_substr($msg, 0, 800);
    $data = $j['data'] ?? null;
    $dataJson = json_encode(is_array($data) ? $data : ['value' => $data], JSON_UNESCAPED_UNICODE);
    if ($dataJson === false) $dataJson = '{}';
    if (strlen($dataJson) > 6000) $dataJson = substr($dataJson, 0, 6000) . '...';
    $logFile = __DIR__ . '/../js_debug.log';
    $entry = date('Y-m-d H:i:s') . ' | IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ' | MSG: ' . $msg . ' | DATA: ' . $dataJson . "\n";
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
  }

  api_ok();
}

