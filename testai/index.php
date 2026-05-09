<?php
declare(strict_types=1);

$ctx = require __DIR__ . '/bootstrap.php';
$date = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
$adminKey = (string)($ctx['adminKey'] ?? '');
$key = trim((string)($_GET['key'] ?? ''));
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TestAI</title>
  <link rel="stylesheet" href="/assets/css/common.css">
  <style>
    body { background: #000; color: rgba(255,255,255,0.92); margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    .wrap { max-width: 980px; margin: 0 auto; padding: 24px 16px 60px; }
    .top { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .top input[type="date"] { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.14); color: rgba(255,255,255,0.92); padding: 10px 12px; border-radius: 10px; }
    .btn { background: rgba(184,135,70,0.95); color: rgba(0,0,0,0.92); border: 0; padding: 10px 14px; border-radius: 10px; font-weight: 900; cursor: pointer; }
    .btn.secondary { background: rgba(255,255,255,0.10); color: rgba(255,255,255,0.92); border: 1px solid rgba(255,255,255,0.16); }
    .box { margin-top: 18px; padding: 16px; border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; background: rgba(255,255,255,0.04); }
    .status { margin-top: 10px; color: rgba(255,255,255,0.65); font-size: 13px; }
    .out a { color: rgba(184,135,70,0.95); }
    .out h2, .out h3 { margin: 0.6em 0 0.2em; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1 style="margin:0 0 12px; font-size: 22px;">TestAI</h1>
    <div class="top">
      <input id="date" type="date" value="<?= htmlspecialchars($date) ?>">
      <button class="btn" id="btnGet" type="button">Показать</button>
      <button class="btn secondary" id="btnGen" type="button">Сгенерировать</button>
    </div>
    <div class="status" id="status"></div>
    <div class="box out" id="out"></div>
  </div>

  <script>
    const qs = (k) => document.getElementById(k)
    const out = qs('out')
    const statusEl = qs('status')
    const dateEl = qs('date')
    const adminKey = <?= json_encode($adminKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const initialKey = <?= json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const mkUrl = (ajax, date) => {
      const u = new URL(location.href)
      u.pathname = '/testai/api.php'
      u.searchParams.set('ajax', ajax)
      u.searchParams.set('date', date)
      if (ajax === 'generate' && adminKey && initialKey) u.searchParams.set('key', initialKey)
      return u.toString()
    }
    const setStatus = (t) => { statusEl.textContent = t || '' }
    const render = (html) => { out.innerHTML = html || '<div>Нет данных</div>' }

    const load = async () => {
      const d = dateEl.value || ''
      setStatus('Загрузка…')
      const res = await fetch(mkUrl('get', d), { headers: { 'Accept': 'application/json' } }).catch(() => null)
      const j = res ? await res.json().catch(() => null) : null
      if (!j || !j.ok) { setStatus('Ошибка'); render(''); return }
      setStatus('')
      render(j.html || '')
    }

    const gen = async () => {
      const d = dateEl.value || ''
      setStatus('Генерация…')
      const res = await fetch(mkUrl('generate', d), { headers: { 'Accept': 'application/json' } }).catch(() => null)
      const j = res ? await res.json().catch(() => null) : null
      if (!j || !j.ok) { setStatus('Ошибка'); render(''); return }
      setStatus('')
      render(j.html || '')
    }

    qs('btnGet').addEventListener('click', load)
    qs('btnGen').addEventListener('click', gen)
    load()
  </script>
</body>
</html>

