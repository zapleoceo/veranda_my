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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$debugKey = (string)($_ENV['POSTER_DEBUG_KEY'] ?? '');
$key = (string)($_GET['key'] ?? '');
if ($debugKey === '' || $key !== $debugKey) {
  http_response_code(403);
  header('Content-Type: text/plain; charset=utf-8');
  echo "Forbidden\n";
  exit;
}

require_once __DIR__ . '/src/classes/PosterAPI.php';

$token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));
if ($token === '') {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "POSTER_API_TOKEN not set\n";
  exit;
}

$spotTzName = trim((string)($_ENV['POSTER_SPOT_TIMEZONE'] ?? ''));
if ($spotTzName === '' || !in_array($spotTzName, timezone_identifiers_list(), true)) {
  $spotTzName = 'Asia/Ho_Chi_Minh';
}
$spotTz = new DateTimeZone($spotTzName);
$tomorrow = (new DateTimeImmutable('tomorrow', $spotTz))->format('Y-m-d');

$dateFrom = trim((string)($_GET['date_from'] ?? ($tomorrow . ' 00:00:00')));
$dateTo = trim((string)($_GET['date_to'] ?? ($tomorrow . ' 23:59:59')));
$status = trim((string)($_GET['status'] ?? ''));
$timezone = trim((string)($_GET['timezone'] ?? 'client'));

$params = [];
if ($status !== '') $params['status'] = $status;
if ($dateFrom !== '') $params['date_from'] = $dateFrom;
if ($dateTo !== '') $params['date_to'] = $dateTo;
if ($timezone !== '') $params['timezone'] = $timezone;

$api = new \App\Classes\PosterAPI($token);
$result = null;
$err = '';
try {
  $result = $api->request('incomingOrders.getReservations', $params, 'GET');
} catch (Throwable $e) {
  $err = $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Poster API test · incomingOrders.getReservations</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 18px; background:#0b0f14; color:#e6edf3; }
    a { color:#7cc4ff; }
    input, select { background:#121a23; color:#e6edf3; border:1px solid #2a3b4c; border-radius:10px; padding:10px 12px; width: 100%; box-sizing:border-box; }
    .row { display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .row3 { display:grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
    .card { background:#0f1620; border:1px solid #223244; border-radius:14px; padding:14px; margin-top:12px; }
    pre { background:#0b1119; border:1px solid #223244; border-radius:14px; padding:12px; overflow:auto; }
    .btn { display:inline-block; background:#2b76c8; color:#fff; border:0; border-radius:12px; padding:10px 14px; cursor:pointer; }
    .muted { color:#9fb1c2; }
  </style>
</head>
<body>
  <h1 style="margin:0 0 6px;">incomingOrders.getReservations</h1>
  <div class="muted">Чистый запрос к Poster API (GET) с параметрами status/date_from/date_to/timezone.</div>

  <div class="card">
    <form method="get" action="">
      <input type="hidden" name="key" value="<?= htmlspecialchars($key) ?>">
      <div class="row">
        <label>
          <div class="muted" style="margin:0 0 6px;">date_from (Y-m-d H:i:s)</div>
          <input name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        </label>
        <label>
          <div class="muted" style="margin:0 0 6px;">date_to (Y-m-d H:i:s)</div>
          <input name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        </label>
      </div>
      <div class="row3" style="margin-top:12px;">
        <label>
          <div class="muted" style="margin:0 0 6px;">status (0/1/7 или пусто)</div>
          <input name="status" value="<?= htmlspecialchars($status) ?>">
        </label>
        <label>
          <div class="muted" style="margin:0 0 6px;">timezone</div>
          <input name="timezone" value="<?= htmlspecialchars($timezone) ?>">
        </label>
        <label style="display:flex; align-items:flex-end;">
          <button class="btn" type="submit">Запрос</button>
        </label>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="muted" style="margin:0 0 8px;">Request params (без token)</div>
    <pre><?= htmlspecialchars(json_encode($params, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
    <div class="muted">spot tz: <?= htmlspecialchars($spotTzName) ?></div>
  </div>

  <?php if ($err !== ''): ?>
    <div class="card">
      <div style="color:#ff8f8f; font-weight:700; margin:0 0 8px;">Error</div>
      <pre><?= htmlspecialchars($err) ?></pre>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="muted" style="margin:0 0 8px;">Poster response</div>
    <pre><?= htmlspecialchars(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
  </div>
</body>
</html>

