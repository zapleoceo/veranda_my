<?php
declare(strict_types=1);

$requireAdmin();

$file = ($log instanceof \App\Classes\TestAILogger) ? $log->filePath() : '';
if ($file === '' || !is_file($file)) {
  $bad('missing_log_file');
  exit;
}

$block = is_object($settingsRepo) ? $settingsRepo->getKey('gemini_block_until') : ['v' => '', 'updated_at' => ''];
$blockUntilStr = is_array($block) ? (string)($block['v'] ?? '') : '';
$blockUntilTs = $blockUntilStr !== '' ? strtotime($blockUntilStr) : false;
$blockRemainingSec = is_int($blockUntilTs) && $blockUntilTs > time() ? ($blockUntilTs - time()) : 0;
$next = is_object($settingsRepo) ? $settingsRepo->getKey('gemini_next_allowed_until') : ['v' => '', 'updated_at' => ''];
$nextUntilStr = is_array($next) ? (string)($next['v'] ?? '') : '';
$nextUntilTs = $nextUntilStr !== '' ? strtotime($nextUntilStr) : false;
$nextRemainingSec = is_int($nextUntilTs) && $nextUntilTs > time() ? ($nextUntilTs - time()) : 0;

$maxBytes = (int)($_GET['max_bytes'] ?? 700000);
$maxBytes = max(50000, min(2500000, $maxBytes));

$size = @filesize($file);
if (!is_int($size) || $size <= 0) {
  $ok(['file' => $file, 'size' => 0, 'events_parsed' => 0, 'counts' => []]);
  exit;
}

$fh = @fopen($file, 'rb');
if (!is_resource($fh)) {
  $bad('cannot_open_log_file');
  exit;
}
$read = min($maxBytes, $size);
@fseek($fh, -$read, SEEK_END);
$buf = (string)@fread($fh, $read);
@fclose($fh);
$buf = str_replace("\r\n", "\n", $buf);
$lines = array_values(array_filter(explode("\n", $buf), fn($x) => trim((string)$x) !== ''));

$now = time();
$counts = [
  'last_60s' => 0,
  'last_10m' => 0,
  'last_60m' => 0,
  'last_24h' => 0,
  'http' => [],
  'tag' => [],
];
$tagWindows = [
  'last_60s' => [],
  'last_10m' => [],
  'last_60m' => [],
  'last_24h' => [],
];
$eventsParsed = 0;
$latest = null;
$latestRate = null;

foreach ($lines as $line) {
  if (strpos($line, '"event":"gemini_http"') === false) continue;
  $j = json_decode($line, true);
  if (!is_array($j) || (string)($j['event'] ?? '') !== 'gemini_http') continue;
  $eventsParsed++;

  $ts = (string)($j['ts'] ?? '');
  $t = strtotime($ts);
  if (!is_int($t) || $t <= 0) continue;

  $ctx = is_array($j['ctx'] ?? null) ? $j['ctx'] : [];
  $code = (int)($ctx['http_code'] ?? 0);
  $err = (string)($ctx['error'] ?? '');
  $tag = trim((string)($ctx['tag'] ?? ''));
  if (!isset($counts['http'][(string)$code])) $counts['http'][(string)$code] = 0;
  $counts['http'][(string)$code]++;
  if ($tag !== '') {
    if (!isset($counts['tag'][$tag])) $counts['tag'][$tag] = 0;
    $counts['tag'][$tag]++;
  }

  $age = $now - $t;
  if ($age <= 60) $counts['last_60s']++;
  if ($age <= 600) $counts['last_10m']++;
  if ($age <= 3600) $counts['last_60m']++;
  if ($age <= 86400) $counts['last_24h']++;
  if ($tag !== '') {
    if ($age <= 60) $tagWindows['last_60s'][$tag] = (int)($tagWindows['last_60s'][$tag] ?? 0) + 1;
    if ($age <= 600) $tagWindows['last_10m'][$tag] = (int)($tagWindows['last_10m'][$tag] ?? 0) + 1;
    if ($age <= 3600) $tagWindows['last_60m'][$tag] = (int)($tagWindows['last_60m'][$tag] ?? 0) + 1;
    if ($age <= 86400) $tagWindows['last_24h'][$tag] = (int)($tagWindows['last_24h'][$tag] ?? 0) + 1;
  }

  if ($latest === null || $t >= (int)($latest['t'] ?? 0)) {
    $latest = [
      'ts' => $ts,
      't' => $t,
      'http_code' => $code,
      'has_error' => $err !== '' ? 1 : 0,
    ];
  }

  if ($err !== '' && preg_match('/limit:\s*(\d+)/i', $err, $m)) {
    $limit = (int)($m[1] ?? 0);
    $retrySec = null;
    if (preg_match('/retry in\s*([0-9.]+)s/i', $err, $m2)) $retrySec = (float)($m2[1] ?? 0);
    $latestRate = [
      'ts' => $ts,
      'http_code' => $code,
      'limit' => $limit,
      'retry_in_sec' => $retrySec,
    ];
  }
}

$assumedLimitPerMin = is_array($latestRate) && (int)($latestRate['limit'] ?? 0) > 0 ? (int)$latestRate['limit'] : null;
$remaining = is_int($assumedLimitPerMin) ? max(0, $assumedLimitPerMin - (int)$counts['last_60s']) : null;

$payload = [
  'mode' => [
    'proxy_base' => (string)$cfg->geminiProxyBase(),
    'via_proxy' => $cfg->geminiProxyBase() !== '' ? 1 : 0,
    'server_has_gemini_key' => trim((string)($cfg->geminiKey ?? '')) !== '' ? 1 : 0,
    'note' => $cfg->geminiProxyBase() !== '' ? 'Gemini API key is used inside Cloudflare Worker (not in server .env)' : 'Gemini API key is used directly from server .env',
  ],
  'file' => $file,
  'size' => $size,
  'events_parsed' => $eventsParsed,
  'counts' => $counts,
  'tag_windows' => $tagWindows,
  'latest' => $latest,
  'latest_rate_limit' => $latestRate,
  'cooldown' => [
    'block_until' => $blockUntilStr,
    'remaining_sec' => $blockRemainingSec,
    'next_allowed_until' => $nextUntilStr,
    'next_remaining_sec' => $nextRemainingSec,
  ],
  'assumed_limit_per_minute' => $assumedLimitPerMin,
  'assumed_remaining_this_minute' => $remaining,
];

if ($wantHtml) {
  $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $fmtBytes = function (int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
    return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
  };

  $nowTs = time();
  $latestTs = is_array($latest) ? (string)($latest['ts'] ?? '') : '';
  $latestAge = $latestTs !== '' ? max(0, $nowTs - (int)strtotime($latestTs)) : null;
  $cooldown = is_array($latestRate) ? (float)($latestRate['retry_in_sec'] ?? 0) : 0.0;
  $cooldownRemaining = $blockRemainingSec > 0 ? $blockRemainingSec : 0;
  $lastRetrySuggestion = $cooldown > 0 ? (int)ceil($cooldown) : 0;

  $refresh = (int)($_GET['refresh'] ?? 5);
  $refresh = max(0, min(60, $refresh));
  $maxBytesInput = (int)($_GET['max_bytes'] ?? 700000);
  $maxBytesInput = max(50000, min(2500000, $maxBytesInput));

  echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>Gemini usage</title>';
  echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:16px;line-height:1.35}';
  echo 'table{border-collapse:collapse;width:auto}td,th{border:1px solid #ddd;padding:6px 8px;vertical-align:top}';
  echo 'th{background:#f7f7f7;text-align:left}code{background:#f3f3f3;padding:2px 4px;border-radius:4px}';
  echo '.row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start}.card{border:1px solid #ddd;border-radius:10px;padding:10px 12px;display:inline-block}';
  echo '.muted{color:#666}.ok{color:#166534}.bad{color:#b91c1c}.warn{color:#92400e}.kpi{font-size:20px;font-weight:700}';
  echo '.top{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px}.btn{display:inline-block;border:1px solid #ccc;border-radius:8px;padding:6px 10px;text-decoration:none;color:#111;background:#fff}';
  echo '.kpis{display:flex;flex-wrap:wrap;gap:10px;margin:12px 0}.kpiCard{border:1px solid #ddd;border-radius:10px;padding:8px 10px;min-width:160px}';
  echo '.kpiLabel{font-size:12px;color:#666}.kpiValue{font-size:18px;font-weight:700}';
  echo '.grid{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start}.stack{display:flex;flex-direction:column;gap:10px}';
  echo '.small th,.small td{padding:4px 6px;font-size:13px}.nowrap{white-space:nowrap}';
  echo '</style></head><body>';
  echo '<div class="top">';
  echo '<div><h2 style="margin:0">Gemini usage</h2><div class="muted">server-side (from logs)</div></div>';
  echo '<div style="flex:1"></div>';
  echo '<a class="btn" href="?ajax=gemini_usage">Refresh</a>';
  echo '<a class="btn" href="?ajax=gemini_usage&pretty=1">JSON pretty</a>';
  echo '<a class="btn" href="?ajax=log_tail&n=120">log_tail</a>';
  echo '</div>';

  echo '<div class="muted" style="margin:6px 0 10px 0">Mode: ' . ($payload['mode']['via_proxy'] ? '<b>proxy</b>' : '<b>direct</b>') . ' · ' . $h((string)$payload['mode']['note']) . '</div>';

  if ($cooldownRemaining > 0) {
    echo '<div class="card" style="border-color:#f59e0b;background:#fffbeb">';
    echo '<div class="warn"><b>Cooldown</b>: wait ~<span id="cooldown">' . (int)$cooldownRemaining . '</span> sec</div>';
    echo '<div class="muted">Bot will not call Gemini until cooldown passes</div>';
    echo '</div>';
  } else {
    echo '<div class="card" style="border-color:#16a34a;background:#f0fdf4">';
    echo '<div class="ok"><b>Ready</b>: no cooldown</div>';
    if ($lastRetrySuggestion > 0) {
      echo '<div class="muted">Last 429 suggested retry in ~' . (int)$lastRetrySuggestion . ' sec</div>';
    }
    echo '</div>';
  }

  echo '<div class="kpis">';
  echo '<div class="kpiCard"><div class="kpiLabel">Limit/min (assumed)</div><div class="kpiValue">' . ($assumedLimitPerMin === null ? '—' : (int)$assumedLimitPerMin) . '</div></div>';
  echo '<div class="kpiCard"><div class="kpiLabel">Used 60s</div><div class="kpiValue">' . (int)($counts['last_60s'] ?? 0) . '</div></div>';
  echo '<div class="kpiCard"><div class="kpiLabel">Used 10m</div><div class="kpiValue">' . (int)($counts['last_10m'] ?? 0) . '</div></div>';
  echo '<div class="kpiCard"><div class="kpiLabel">Used 60m</div><div class="kpiValue">' . (int)($counts['last_60m'] ?? 0) . '</div></div>';
  echo '<div class="kpiCard"><div class="kpiLabel">Remaining/min (assumed)</div><div class="kpiValue">' . ($remaining === null ? '—' : (int)$remaining) . '</div></div>';
  echo '</div>';

  echo '<div class="grid">';
  echo '<div class="card">';
  echo '<div class="muted">Log</div>';
  echo '<div class="nowrap"><code>' . $h($file) . '</code></div>';
  echo '<div class="muted">Size: ' . $h($fmtBytes((int)$size)) . ' · Parsed: ' . (int)$eventsParsed . '</div>';
  echo '<div class="muted">max_bytes: ' . (int)$maxBytesInput . '</div>';
  echo '</div>';
  echo '<div class="card">';
  echo '<div class="muted">Latest</div>';
  echo '<div class="nowrap"><b>ts</b>: ' . $h($latestTs !== '' ? $latestTs : '—') . '</div>';
  echo '<div class="nowrap"><b>age</b>: ' . ($latestAge === null ? '—' : ((int)$latestAge . ' sec')) . '</div>';
  echo '<div class="nowrap"><b>http</b>: ' . (is_array($latest) ? (int)($latest['http_code'] ?? 0) : 0) . '</div>';
  echo '</div>';
  echo '<div class="card">';
  echo '<div class="muted">Auto refresh</div>';
  echo '<div class="nowrap"><a class="btn" href="?ajax=gemini_usage&refresh=0">off</a> ';
  echo '<a class="btn" href="?ajax=gemini_usage&refresh=2">2s</a> ';
  echo '<a class="btn" href="?ajax=gemini_usage&refresh=5">5s</a> ';
  echo '<a class="btn" href="?ajax=gemini_usage&refresh=10">10s</a></div>';
  echo '</div>';
  echo '</div>';

  $http = is_array($counts['http'] ?? null) ? $counts['http'] : [];
  ksort($http);
  echo '<div class="grid" style="margin-top:12px">';
  echo '<div class="card"><div class="muted">HTTP codes</div>';
  echo '<table class="small"><thead><tr><th class="nowrap">Code</th><th class="nowrap">Count</th></tr></thead><tbody>';
  foreach ($http as $code => $cnt) {
    echo '<tr><td>' . $h((string)$code) . '</td><td>' . (int)$cnt . '</td></tr>';
  }
  if (!$http) echo '<tr><td colspan="2">—</td></tr>';
  echo '</tbody></table>';
  echo '</div>';

  $tags = is_array($counts['tag'] ?? null) ? $counts['tag'] : [];
  arsort($tags);
  echo '<div class="card"><div class="muted">Tags (total in window)</div>';
  echo '<table class="small"><thead><tr><th class="nowrap">Tag</th><th class="nowrap">Count</th></tr></thead><tbody>';
  foreach ($tags as $tag => $cnt) echo '<tr><td>' . $h((string)$tag) . '</td><td>' . (int)$cnt . '</td></tr>';
  if (!$tags) echo '<tr><td colspan="2">No tags yet</td></tr>';
  echo '</tbody></table></div>';

  $tw10 = is_array($tagWindows['last_10m'] ?? null) ? $tagWindows['last_10m'] : [];
  arsort($tw10);
  echo '<div class="card"><div class="muted">Tags (last 10m)</div>';
  echo '<table class="small"><thead><tr><th class="nowrap">Tag</th><th class="nowrap">Calls</th></tr></thead><tbody>';
  foreach ($tw10 as $tag => $cnt) echo '<tr><td>' . $h((string)$tag) . '</td><td>' . (int)$cnt . '</td></tr>';
  if (!$tw10) echo '<tr><td colspan="2">—</td></tr>';
  echo '</tbody></table></div>';
  echo '</div>';

  if ($refresh > 0) {
    echo '<script>(function(){var s=' . (int)$refresh . ';var el=document.getElementById("cooldown");';
    echo 'setInterval(function(){if(el){var v=parseInt(el.textContent||"0",10);if(v>0)el.textContent=String(v-1);} },1000);';
    echo 'setTimeout(function(){location.reload();}, s*1000);})();</script>';
  }
  echo '</body></html>';
  exit;
}

$pretty = ((string)($_GET['pretty'] ?? '') === '1');
if ($pretty) {
  print json_encode(array_merge(['ok' => true], $payload), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

$ok($payload);
exit;

