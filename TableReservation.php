<?php
date_default_timezone_set('Asia/Ho_Chi_Minh');

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

require_once __DIR__ . '/src/classes/PosterAPI.php';

$posterToken = trim((string)($_ENV['POSTER_API_TOKEN'] ?? ''));

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

  $ts = strtotime($dateReservation);
  if ($ts === false || $dateReservation === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректная дата'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($duration < 1800) $duration = 7200;
  if ($guests <= 0) $guests = 2;
  if ($spotId <= 0) $spotId = 1;

  $api = new \App\Classes\PosterAPI($posterToken);
  try {
    $resp = $api->request('incomingOrders.getTablesForReservation', [
      'date_reservation' => date('Y-m-d H:i:s', $ts),
      'duration' => $duration,
      'spot_id' => $spotId,
      'guests_count' => $guests,
    ], 'GET');

    $free = is_array($resp) && isset($resp['freeTables']) && is_array($resp['freeTables']) ? $resp['freeTables'] : [];
    $filtered = [];
    $nums = [];
    foreach ($free as $row) {
      if (!is_array($row)) continue;
      if ((int)($row['hall_id'] ?? 0) !== $hallId) continue;
      $num = trim((string)($row['table_num'] ?? ''));
      if ($num === '') continue;
      $filtered[] = $row;
      $nums[$num] = true;
    }

    echo json_encode([
      'ok' => true,
      'request' => [
        'date_reservation' => date('Y-m-d H:i:s', $ts),
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

?><!doctype html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Restaurant Table Map</title>
  <link rel="preconnect" href="https://api.fontshare.com">
  <link rel="preconnect" href="https://cdn.fontshare.com" crossorigin>
  <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700&f[]=clash-display@500,600&display=swap" rel="stylesheet">
  <style>
    :root {
      --text-xs: clamp(0.75rem, 0.7rem + 0.25vw, 0.875rem);
      --text-sm: clamp(0.875rem, 0.8rem + 0.35vw, 1rem);
      --text-base: clamp(1rem, 0.95rem + 0.25vw, 1.125rem);
      --text-lg: clamp(1.125rem, 1rem + 0.75vw, 1.5rem);
      --text-xl: clamp(1.5rem, 1.2rem + 1.25vw, 2.25rem);
      --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 0.75rem; --space-4: 1rem; --space-5: 1.25rem; --space-6: 1.5rem; --space-8: 2rem; --space-10: 2.5rem; --space-12: 3rem;
      --color-bg: #f5f2ea;
      --color-surface: #fcfbf7;
      --color-surface-2: #f0ece3;
      --color-border: rgba(43, 36, 28, 0.12);
      --color-text: #2b241c;
      --color-text-muted: #746a60;
      --color-primary: #7b4b2a;
      --color-primary-strong: #5f3417;
      --color-accent: #c89a63;
      --color-success: #4f7b4b;
      --shadow-sm: 0 8px 20px rgba(43, 36, 28, 0.08);
      --shadow-lg: 0 20px 60px rgba(43, 36, 28, 0.14);
      --radius-md: 16px;
      --radius-lg: 24px;
      --radius-full: 999px;
      --font-body: 'Satoshi', sans-serif;
      --font-display: 'Clash Display', 'Satoshi', sans-serif;
    }
  
    [data-theme="dark"] {
      --color-bg: #181513;
      --color-surface: #23201c;
      --color-surface-2: #2b2722;
      --color-border: rgba(255, 245, 232, 0.1);
      --color-text: #f5eee4;
      --color-text-muted: #b7ab9d;
      --color-primary: #d59c5a;
      --color-primary-strong: #f0bd7d;
      --color-accent: #7b4b2a;
      --shadow-sm: 0 8px 20px rgba(0, 0, 0, 0.22);
      --shadow-lg: 0 20px 60px rgba(0, 0, 0, 0.34);
    }
  
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body {
      font-family: var(--font-body);
      background:
        radial-gradient(circle at top left, rgba(200,154,99,.12), transparent 28%),
        radial-gradient(circle at right bottom, rgba(123,75,42,.08), transparent 24%),
        var(--color-bg);
      color: var(--color-text);
    }
  
    .app {
      min-height: 100vh;
      padding: var(--space-6);
      display: grid;
      place-items: center;
    }
  
    .panel {
      width: min(1320px, 100%);
      background: linear-gradient(180deg, rgba(255,255,255,.35), transparent), var(--color-surface);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lg);
      overflow: hidden;
    }
  
    .topbar {
      display: flex;
      justify-content: space-between;
      gap: var(--space-4);
      align-items: center;
      padding: var(--space-5) var(--space-6);
      border-bottom: 1px solid var(--color-border);
      background: rgba(255,255,255,0.24);
      backdrop-filter: blur(14px);
    }
  
    .title-wrap h1 {
      margin: 0;
      font-family: var(--font-display);
      font-size: var(--text-xl);
      line-height: 1;
      letter-spacing: -0.03em;
    }
  
    .title-wrap p {
      margin: var(--space-2) 0 0;
      color: var(--color-text-muted);
      font-size: var(--text-sm);
    }
  
    .controls {
      display: flex;
      gap: var(--space-3);
      align-items: center;
      flex-wrap: wrap;
    }
  
    .legend, .theme-toggle {
      border: 1px solid var(--color-border);
      background: var(--color-surface-2);
      border-radius: var(--radius-full);
      padding: var(--space-2) var(--space-4);
      font-size: var(--text-sm);
      color: var(--color-text);
    }
  
    .theme-toggle { cursor: pointer; }
  
    .layout {
      padding: var(--space-6);
      display: grid;
      grid-template-columns: 1fr 280px;
      gap: var(--space-6);
    }
  
    .map-shell {
      background:
        linear-gradient(var(--color-border) 1px, transparent 1px),
        linear-gradient(90deg, var(--color-border) 1px, transparent 1px),
        var(--color-surface-2);
      background-size: 28px 28px;
      border-radius: calc(var(--radius-lg) - 8px);
      padding: var(--space-6);
      border: 1px solid var(--color-border);
      overflow: auto;
    }
  
    .map {
      --mx: 1;
      --my: 1;
      --sx: -56px;
      --sy: -56px;
      position: relative;
      min-width: 820px;
      min-height: 620px;
      border-radius: var(--radius-md);
      transform: translate(var(--sx), var(--sy)) scale(var(--mx), var(--my));
      transform-origin: center;
    }
    .map.is-mirrored {
      --mx: -1;
      --my: -1;
    }

    .grass-area {
      position: absolute;
      left: -42px;
      top: -40px;
      width: 944px;
      height: 430px;
      clip-path: polygon(0 0, 100% 0, 100% 184px, 224px 184px, 224px 100%, 0 100%);
      background:
        radial-gradient(circle at 12% 24%, rgba(147,196,125,0.20), transparent 58%),
        radial-gradient(circle at 78% 30%, rgba(92,162,92,0.22), transparent 62%),
        radial-gradient(circle at 44% 78%, rgba(200,154,99,0.10), transparent 60%),
        repeating-linear-gradient(115deg, rgba(34,88,34,0.22) 0 2px, rgba(52,116,52,0.18) 2px 5px),
        repeating-linear-gradient(25deg, rgba(72,148,72,0.16) 0 3px, rgba(28,84,28,0.14) 3px 7px),
        linear-gradient(180deg, rgba(255,255,255,0.06), rgba(0,0,0,0.10)),
        rgba(52,116,52,0.12);
      background-blend-mode: screen, screen, normal, overlay, overlay, normal, normal;
      border: 1px solid rgba(255,255,255,0.12);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), inset 0 -18px 30px rgba(0,0,0,0.20);
      pointer-events: none;
      opacity: 0.98;
      filter: saturate(1.35) contrast(1.05);
    }
    .grass-area::before,
    .grass-area::after {
      content: '';
      position: absolute;
      inset: 0;
      pointer-events: none;
      clip-path: polygon(0 0, 100% 0, 100% 184px, 224px 184px, 224px 100%, 0 100%);
    }
    .grass-area::before {
      transform: rotate(-2deg) translate(-14px, 10px);
      background:
        radial-gradient(circle at 22% 58%, rgba(52,116,52,0.20), transparent 58%),
        radial-gradient(circle at 60% 18%, rgba(147,196,125,0.14), transparent 60%),
        radial-gradient(circle at 88% 62%, rgba(34,88,34,0.16), transparent 60%);
      opacity: 0.85;
      filter: blur(0.2px);
    }
    .grass-area::after {
      transform: rotate(1.4deg) translate(18px, -10px);
      background:
        radial-gradient(circle at 10% 26%, rgba(255,255,255,0.06), transparent 52%),
        radial-gradient(circle at 74% 78%, rgba(0,0,0,0.18), transparent 62%);
      opacity: 0.65;
    }
  
    .table .cap {
      display: block;
      font-size: 0.75rem;
      letter-spacing: 0;
      font-weight: 700;
      opacity: 0.85;
      margin-top: 2px;
      font-family: var(--font-body);
    }

    .bar-row {
      position: absolute;
      left: 50%;
      bottom: 28px;
      transform: translateX(-50%) scale(var(--mx), var(--my));
      display: flex;
      align-items: center;
      gap: 56px;
      user-select: none;
    }

    .bar {
      width: 260px;
      height: 72px;
      border-radius: 36px;
      background: linear-gradient(180deg, var(--color-primary), var(--color-primary-strong));
      color: #fff8ef;
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-size: 1.9rem;
      letter-spacing: 0.08em;
      box-shadow: var(--shadow-sm);
    }

    .side-station {
      width: 170px;
      height: 58px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.16);
      background: linear-gradient(180deg, rgba(255,255,255,0.10), rgba(0,0,0,0.10)), rgba(255,255,255,0.04);
      box-shadow: 0 12px 20px rgba(0,0,0,0.22);
      color: rgba(245,238,228,0.92);
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-size: 1.05rem;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }

    .fountain {
      position: absolute;
      width: 84px;
      height: 84px;
      border-radius: 50%;
      background:
        radial-gradient(circle at 50% 50%, rgba(255,255,255,0.40), rgba(255,255,255,0.08) 38%, transparent 58%),
        radial-gradient(circle at 50% 55%, rgba(90,180,255,0.62), rgba(35,110,180,0.34) 55%, rgba(10,40,70,0.20) 100%),
        rgba(35,110,180,0.18);
      border: 1px solid rgba(255,255,255,0.16);
      box-shadow: 0 16px 30px rgba(0,0,0,0.22), inset 0 2px 8px rgba(255,255,255,0.10);
      pointer-events: none;
      z-index: 1;
      transform: scale(var(--mx), var(--my));
      transform-origin: center;
    }
    .fountain svg {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      opacity: 0.92;
      filter: drop-shadow(0 8px 16px rgba(0,0,0,0.22));
      pointer-events: none;
    }
    .koi {
      position: absolute;
      left: 50%;
      top: 50%;
      width: 16px;
      height: 10px;
      border-radius: 999px;
      background:
        radial-gradient(circle at 25% 45%, rgba(255,255,255,0.92), rgba(255,255,255,0) 52%),
        radial-gradient(circle at 60% 55%, rgba(255,255,255,0.20), rgba(255,255,255,0) 62%),
        linear-gradient(180deg, #ffb14a, #e87422);
      box-shadow: 0 6px 10px rgba(0,0,0,0.18);
      transform-origin: center;
      opacity: 0.95;
    }
    .koi::before {
      content: '';
      position: absolute;
      left: 2px;
      top: 50%;
      width: 3px;
      height: 3px;
      border-radius: 50%;
      background: rgba(0,0,0,0.5);
      transform: translateY(-50%);
    }
    .koi::after {
      content: '';
      position: absolute;
      right: -6px;
      top: 50%;
      width: 10px;
      height: 10px;
      background: linear-gradient(180deg, rgba(255,190,90,0.9), rgba(232,116,34,0.9));
      clip-path: polygon(0 50%, 100% 0, 100% 100%);
      transform: translateY(-50%);
      border-radius: 2px;
      opacity: 0.95;
    }
    .koi.koi-1 { animation: koiOrbit1 2.6s linear infinite; }
    .koi.koi-2 {
      animation: koiOrbit2 3.1s linear infinite;
      filter: hue-rotate(-8deg) saturate(1.1);
      opacity: 0.92;
    }
    @keyframes koiOrbit1 {
      from { transform: translate(-50%, -50%) rotate(0deg) translate(22px); }
      to { transform: translate(-50%, -50%) rotate(360deg) translate(22px); }
    }
    @keyframes koiOrbit2 {
      from { transform: translate(-50%, -50%) rotate(180deg) translate(20px); }
      to { transform: translate(-50%, -50%) rotate(-180deg) translate(20px); }
    }
  
    .table {
      position: absolute;
      width: 74px;
      height: 74px;
      border: 1px solid rgba(255,255,255,.24);
      border-radius: 22px;
      background: linear-gradient(180deg, #b58a63, #8b5e3b);
      color: #fffaf4;
      box-shadow: 0 14px 24px rgba(84, 49, 20, .22);
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-size: 1.45rem;
      font-weight: 600;
      letter-spacing: -0.03em;
      cursor: pointer;
      transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
      user-select: none;
      transform: scale(var(--mx), var(--my));
      transform-origin: center;
    }
  
    .table::after {
      content: '';
      position: absolute;
      inset: auto 10px 10px auto;
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background: rgba(255,255,255,.35);
      opacity: .6;
    }
  
    .table:hover, .table:focus-visible {
      transform: translateY(-3px) scale(1.02) scale(var(--mx), var(--my));
      box-shadow: 0 18px 34px rgba(84, 49, 20, .3);
      filter: saturate(1.05);
      outline: none;
    }
  
    .table.selected {
      background: linear-gradient(180deg, #4f7b4b, #355b33);
      box-shadow: 0 18px 34px rgba(43, 89, 50, .28);
    }
  
    .table.small-vertical { width: 58px; height: 92px; border-radius: 18px; }
    .table.wide { width: 112px; height: 58px; border-radius: 18px; }
    .table.large { width: 108px; height: 108px; border-radius: 26px; }
  
    .sidebar {
      display: grid;
      gap: var(--space-4);
      align-content: start;
    }
  
    .card {
      background: var(--color-surface-2);
      border: 1px solid var(--color-border);
      border-radius: calc(var(--radius-lg) - 8px);
      padding: var(--space-5);
      box-shadow: var(--shadow-sm);
    }
  
    .card h2 {
      margin: 0 0 var(--space-3);
      font-size: var(--text-lg);
      font-family: var(--font-display);
      line-height: 1.1;
    }
  
    .card p, .card li {
      color: var(--color-text-muted);
      font-size: var(--text-sm);
      margin: 0;
    }
  
    label {
      display: grid;
      gap: var(--space-2);
      font-size: var(--text-sm);
      color: var(--color-text-muted);
      margin-top: var(--space-3);
    }
    input[type="datetime-local"], input[type="number"] {
      width: 100%;
      border-radius: 14px;
      border: 1px solid var(--color-border);
      background: rgba(255,255,255,0.04);
      color: var(--color-text);
      padding: 0.85rem 0.9rem;
      font-size: var(--text-sm);
      outline: none;
    }
    input[type="datetime-local"] {
      padding-left: 2.4rem;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none'%3E%3Cpath d='M8 3v3M16 3v3' stroke='rgba(245,238,228,0.72)' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M4 9h16' stroke='rgba(245,238,228,0.38)' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M6 6h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Z' stroke='rgba(245,238,228,0.48)' stroke-width='2'/%3E%3Cpath d='M8 13h2M12 13h2M16 13h0M8 17h2M12 17h2' stroke='rgba(245,238,228,0.62)' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: 0.85rem 50%;
      background-size: 18px 18px;
    }
    input[type="number"] { padding-left: 2.4rem; }
    input[type="number"] {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='18' viewBox='0 0 24 24' fill='none'%3E%3Cpath d='M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2' stroke='rgba(245,238,228,0.48)' stroke-width='2' stroke-linecap='round'/%3E%3Ccircle cx='9' cy='7' r='4' stroke='rgba(245,238,228,0.62)' stroke-width='2'/%3E%3Cpath d='M22 21v-2a4 4 0 0 0-3-3.87' stroke='rgba(245,238,228,0.38)' stroke-width='2' stroke-linecap='round'/%3E%3Cpath d='M19 3.13a4 4 0 0 1 0 7.75' stroke='rgba(245,238,228,0.38)' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: 0.85rem 50%;
      background-size: 18px 18px;
    }
    input[type="datetime-local"]:focus, input[type="number"]:focus {
      border-color: rgba(213,156,90,0.55);
      box-shadow: 0 0 0 4px rgba(213,156,90,0.12);
    }
    textarea {
      width: 100%;
      min-height: 220px;
      border-radius: 14px;
      border: 1px solid var(--color-border);
      background: rgba(0,0,0,0.12);
      color: var(--color-text);
      padding: 0.85rem 0.9rem;
      font-size: 12px;
      line-height: 1.35;
      resize: vertical;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }

    .selected-output {
      margin-top: var(--space-4);
      padding: var(--space-4);
      border-radius: var(--radius-md);
      background: rgba(123,75,42,.08);
      color: var(--color-text);
      min-height: 74px;
    }
  
    .selected-list {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin-top: var(--space-3);
    }
  
    .pill {
      background: rgba(123,75,42,.12);
      color: var(--color-primary-strong);
      padding: 0.45rem 0.8rem;
      border-radius: var(--radius-full);
      font-size: var(--text-sm);
      font-weight: 700;
    }
  
    .actions {
      display: flex;
      gap: var(--space-3);
      flex-wrap: wrap;
      margin-top: var(--space-4);
    }
  
    .btn {
      border: 0;
      border-radius: var(--radius-full);
      padding: 0.9rem 1rem;
      font-size: var(--text-sm);
      font-weight: 700;
      cursor: pointer;
    }
  
    .btn-primary { background: var(--color-primary); color: #fffaf4; }
    .btn-secondary { background: transparent; color: var(--color-text); border: 1px solid var(--color-border); }
  
    .stats { display: none; }
    .stat { display: none; }
    .footer-note { display: none; }

    .table.free {
      outline: 2px solid rgba(79,123,75,0.85);
      outline-offset: 2px;
    }
    .table.busy {
      opacity: 0.5;
      filter: grayscale(0.2);
    }
  
    @media (max-width: 980px) {
      .layout { grid-template-columns: 1fr; }
      .map { min-width: 720px; }
    }
  
    @media (max-width: 640px) {
      .app, .layout, .map-shell { padding: var(--space-4); }
      .topbar { padding: var(--space-4); align-items: flex-start; flex-direction: column; }
      .map { min-width: 640px; min-height: 600px; }
    }
  </style>
</head>
<body>
  <div class="app">
    <main class="panel">
      <div class="topbar">
        <div class="title-wrap">
          <h1>Схема бронирования</h1>
        </div>
        <div class="controls">
          <button class="theme-toggle" type="button" data-theme-toggle aria-label="Переключить тему">☀️</button>
        </div>
      </div>
  
      <section class="layout">
        <div class="map-shell">
          <div class="map is-mirrored" aria-label="Схема столов ресторана">
            <div class="grass-area" aria-hidden="true"></div>
            <button class="table large" style="left: 0px; top: 24px;" data-table="1">1<span class="cap">до 8</span></button>
            <button class="table large" style="left: 0px; top: 150px;" data-table="2">2<span class="cap">до 8</span></button>
            <button class="table large" style="left: 0px; top: 276px;" data-table="3">3<span class="cap">до 8</span></button>
  
            <button class="table small-vertical" style="left: 200px; top: 0px;" data-table="4">4<span class="cap">до 5</span></button>
            <button class="table small-vertical" style="left: 364px; top: 0px;" data-table="5">5<span class="cap">до 5</span></button>
            <button class="table small-vertical" style="left: 512px; top: 0px;" data-table="6">6<span class="cap">до 5</span></button>
            <button class="table large" style="left: 700px; top: 0px;" data-table="7">7<span class="cap">до 8</span></button>
  
            <button class="table wide" style="left: 286px; top: 142px;" data-table="8">8<span class="cap">2 чел</span></button>
            <button class="table wide" style="left: 408px; top: 142px;" data-table="9">9<span class="cap">2 чел</span></button>
            <div class="fountain" style="left: 174px; top: 128px;" aria-hidden="true">
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
                <path d="M32 18c0 10-6 12-6 20" stroke="url(#fWat)" stroke-width="3" stroke-linecap="round"/>
                <path d="M32 18c0 10 6 12 6 20" stroke="url(#fWat)" stroke-width="3" stroke-linecap="round"/>
                <path d="M32 14c0 10 0 14 0 24" stroke="rgba(255,255,255,0.78)" stroke-width="3" stroke-linecap="round"/>
                <circle cx="32" cy="14" r="3" fill="rgba(255,255,255,0.75)"/>
                <path d="M24 40h16c0 0 2 0 2 2s-2 2-2 2H24c0 0-2 0-2-2s2-2 2-2Z" fill="url(#fBowl)" stroke="rgba(255,255,255,0.16)" stroke-width="1"/>
              </svg>
              <div class="koi koi-1"></div>
              <div class="koi koi-2"></div>
            </div>
            <button class="table wide" style="left: 606px; top: 142px;" data-table="10">10<span class="cap">2 чел</span></button>
            <button class="table wide" style="left: 728px; top: 142px;" data-table="11">11<span class="cap">2 чел</span></button>
  
            <button class="table" style="left: 344px; top: 242px;" data-table="12">12<span class="cap">до 3</span></button>
            <button class="table" style="left: 472px; top: 242px;" data-table="13">13<span class="cap">до 3</span></button>
            <button class="table" style="left: 584px; top: 242px;" data-table="14">14<span class="cap">до 3</span></button>
  
            <button class="table small-vertical" style="left: 270px; top: 336px;" data-table="15">15<span class="cap">до 5</span></button>
            <button class="table small-vertical" style="left: 370px; top: 336px;" data-table="16">16<span class="cap">до 5</span></button>
            <button class="table small-vertical" style="left: 470px; top: 336px;" data-table="17">17<span class="cap">до 5</span></button>
            <button class="table small-vertical" style="left: 570px; top: 336px;" data-table="18">18<span class="cap">до 5</span></button>
            <button class="table small-vertical" style="left: 670px; top: 336px;" data-table="19">19<span class="cap">до 5</span></button>
            <button class="table large" style="left: 758px; top: 258px;" data-table="20">20<span class="cap">до 15</span></button>
  
            <div class="bar-row" aria-hidden="true">
              <div class="side-station">Музыканты</div>
              <div class="bar">BAR</div>
              <div class="side-station">Касса</div>
            </div>
          </div>
        </div>
  
        <aside class="sidebar">
          <div class="card">
            <h2>Бронь</h2>
            <label>
              Дата и время
              <input type="datetime-local" id="resDate">
            </label>
            <div id="stepGuests" hidden>
              <label>
                Гостей
                <input type="number" id="resGuests" min="1" max="30" value="2">
              </label>
            </div>
            <div class="actions" id="stepCheck" hidden>
              <button class="btn btn-primary" id="checkBtn" type="button">Проверить свободные столы</button>
            </div>
            <div class="selected-output">
              <div style="display:flex; justify-content: space-between; gap: 10px; align-items: baseline; flex-wrap: wrap;">
                <div>Стол: <strong id="selectedTable">—</strong></div>
                <div style="color:var(--color-text-muted); font-size: var(--text-sm);" id="statusLine">—</div>
              </div>
              <div style="margin-top: var(--space-3);">
                <textarea id="resultText" readonly></textarea>
              </div>
            </div>
          </div>
  
  
        </aside>
      </section>
    </main>
  </div>
  
  <script>
    const root = document.documentElement;
    const toggle = document.querySelector('[data-theme-toggle]');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    root.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
    toggle.textContent = prefersDark ? '☀️' : '🌙';
  
    toggle.addEventListener('click', () => {
      const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      toggle.textContent = next === 'dark' ? '☀️' : '🌙';
    });
  
    const tables = Array.from(document.querySelectorAll('.table'));
    const resDate = document.getElementById('resDate');
    const resGuests = document.getElementById('resGuests');
    const checkBtn = document.getElementById('checkBtn');
    const resultText = document.getElementById('resultText');
    const selectedTableEl = document.getElementById('selectedTable');
    const statusLine = document.getElementById('statusLine');
    const stepGuests = document.getElementById('stepGuests');
    const stepCheck = document.getElementById('stepCheck');

    let last = null;
    let freeNums = new Set();
    let lastKey = '';
    let selectedTableNum = '';
    let isLoading = false;

    const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const fmtJson = (x) => {
      try { return JSON.stringify(x, null, 2); } catch (_) { return String(x); }
    };

    const setOutput = (obj) => {
      if (!resultText) return;
      if (typeof obj === 'string') {
        resultText.value = obj;
        return;
      }
      resultText.value = fmtJson(obj);
    };

    const formatAvailabilityText = (j, selectedNum) => {
      if (!j || typeof j !== 'object') return 'Нет данных';

      const req = (j && typeof j.request === 'object' && j.request) ? j.request : {};
      const numsRaw = Array.isArray(j.free_table_nums) ? j.free_table_nums.map(String) : [];
      const nums = numsRaw
        .filter((x) => x !== '')
        .slice()
        .sort((a, b) => (Number(a) || 0) - (Number(b) || 0));

      const selected = selectedNum ? String(selectedNum) : '';
      const isFree = selected ? nums.includes(selected) : false;
      const freeTables = Array.isArray(j.free_tables) ? j.free_tables : [];
      const row = selected ? (freeTables.find((r) => r && String(r.table_num ?? '') === selected) || null) : null;

      const lines = [];
      lines.push('Результат проверки свободных столов');
      lines.push('');
      lines.push('Запрос:');
      lines.push(`- date_reservation: ${String(req.date_reservation ?? '')}`);
      lines.push(`- duration: ${String(req.duration ?? '')}`);
      lines.push(`- guests_count: ${String(req.guests_count ?? '')}`);
      lines.push(`- spot_id: ${String(req.spot_id ?? '')}`);
      lines.push(`- hall_id: ${String(req.hall_id ?? '')}`);
      lines.push('');
      lines.push(`Свободных столов (hall_id=${String(req.hall_id ?? '')}): ${nums.length}`);
      lines.push(`Номера: ${nums.length ? nums.join(', ') : '—'}`);

      if (selected) {
        lines.push('');
        lines.push(`Выбранный стол: ${selected}`);
        lines.push(`Статус: ${isFree ? 'СВОБОДЕН' : 'ЗАНЯТ'}`);
        if (row) {
          lines.push('');
          lines.push('Данные по столу из API (freeTables):');
          Object.keys(row).sort().forEach((k) => {
            const v = row[k];
            lines.push(`- ${k}: ${typeof v === 'object' ? fmtJson(v) : String(v)}`);
          });
        }
      }

      if (j.raw) {
        lines.push('');
        lines.push('RAW (как вернул Poster):');
        lines.push(fmtJson(j.raw));
      }

      return lines.join('\n');
    };

    const setStatus = (tableNum) => {
      if (selectedTableEl) selectedTableEl.textContent = tableNum ? String(tableNum) : '—';
      if (!tableNum) {
        if (statusLine) statusLine.textContent = '—';
        return;
      }
      if (isLoading) {
        if (statusLine) statusLine.textContent = 'Проверяю…';
        return;
      }
      if (!last) {
        if (statusLine) statusLine.textContent = 'Нажми "Проверить свободные столы"';
        return;
      }
      const isFree = freeNums.has(String(tableNum));
      if (statusLine) statusLine.textContent = isFree ? 'Свободен' : 'Занят';
    };

    const applyAvailabilityStyles = () => {
      tables.forEach((t) => {
        const n = String(t.dataset.table || '');
        t.classList.remove('free', 'busy');
        if (!last) return;
        if (freeNums.has(n)) t.classList.add('free');
        else t.classList.add('busy');
      });
    };

    const getCurrentRequest = () => {
      const dtRaw = resDate ? String(resDate.value || '').trim() : '';
      const guestsRaw = resGuests ? Number(resGuests.value || 0) : 0;
      if (!dtRaw) return null;
      const dt = dtRaw.replace('T', ' ') + ':00';
      const guests = isFinite(guestsRaw) && guestsRaw > 0 ? Math.floor(guestsRaw) : 2;
      return { dt, guests };
    };

    const invalidateLast = () => {
      last = null;
      freeNums = new Set();
      lastKey = '';
      applyAvailabilityStyles();
      renderSelectedTable();
    };

    const renderSelectedTable = () => {
      setStatus(selectedTableNum);
      if (!selectedTableNum) return;
      if (!last) {
        setOutput('Нажми "Проверить свободные столы"');
        return;
      }
      setOutput(formatAvailabilityText(last, selectedTableNum));
    };
  
    tables.forEach(table => {
      table.addEventListener('click', async () => {
        const id = String(table.dataset.table || '');
        selectedTableNum = id;
        tables.forEach((t) => t.classList.remove('selected'));
        table.classList.add('selected');

        const current = getCurrentRequest();
        if (!current) {
          setStatus(id);
          setOutput({ ok: false, error: 'Выбери дату и время' });
          return;
        }

        const key = current.dt + '|' + String(current.guests);
        if ((!last || lastKey !== key) && !isLoading) {
          try {
            await loadFree(true);
          } catch (e) {
            setOutput({ ok: false, error: String(e && e.message ? e.message : e) });
            return;
          }
        }

        renderSelectedTable();
      });
    });

    const initDate = () => {
      if (!resDate) return;
      resDate.value = '';
    };

    const syncSteps = () => {
      const hasDate = !!(resDate && String(resDate.value || '').trim());
      if (stepGuests) stepGuests.hidden = !hasDate;
      if (stepCheck) stepCheck.hidden = !hasDate;
      if (!hasDate) invalidateLast();
    };

    const loadFree = async (silent) => {
      if (isLoading) return;
      const current = getCurrentRequest();
      if (!current) {
        setOutput({ ok: false, error: 'Выбери дату и время' });
        return;
      }

      const dt = current.dt;
      const guests = current.guests;
      const key = dt + '|' + String(guests);

      isLoading = true;
      if (statusLine) statusLine.textContent = 'Проверяю…';
      if (checkBtn) checkBtn.disabled = true;

      const url = new URL(location.href);
      url.searchParams.set('ajax', 'free_tables');
      url.searchParams.set('date_reservation', dt);
      url.searchParams.set('duration', '7200');
      url.searchParams.set('spot_id', '1');
      url.searchParams.set('guests_count', String(guests));

      try {
        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
        const j = await res.json().catch(() => null);
        if (!res.ok || !j || !j.ok) {
          last = null;
          freeNums = new Set();
          lastKey = '';
          applyAvailabilityStyles();
          setOutput(j && typeof j === 'object' ? fmtJson(j) : 'Ошибка запроса');
          renderSelectedTable();
          return;
        }

        last = j;
        lastKey = key;
        freeNums = new Set(Array.isArray(j.free_table_nums) ? j.free_table_nums.map(String) : []);
        applyAvailabilityStyles();
        if (!silent) setOutput(formatAvailabilityText(j, selectedTableNum));
        renderSelectedTable();
      } finally {
        isLoading = false;
        if (checkBtn) checkBtn.disabled = false;
        setStatus(selectedTableNum);
      }
    };

    if (checkBtn) {
      checkBtn.addEventListener('click', () => {
        loadFree(false).catch((e) => setOutput('Ошибка: ' + String(e && e.message ? e.message : e)));
      });
    }

    initDate();
    syncSteps();
    if (resDate) {
      resDate.addEventListener('input', () => { syncSteps(); invalidateLast(); });
      resDate.addEventListener('change', () => { syncSteps(); invalidateLast(); });
    }
    if (resGuests) {
      resGuests.addEventListener('input', invalidateLast);
      resGuests.addEventListener('change', invalidateLast);
    }
    setOutput('Выбери дату. Потом укажи гостей и нажми "Проверить свободные столы". После этого кликай по столам.');
  </script>
</body>
</html>
