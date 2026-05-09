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
    .hint { margin-top: 4px; color: rgba(255,255,255,0.55); font-size: 12px; }
    .out a { color: rgba(184,135,70,0.95); }
    .out h2, .out h3 { margin: 0.6em 0 0.2em; }
    textarea { width: 100%; min-height: 110px; resize: vertical; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.14); color: rgba(255,255,255,0.92); padding: 12px; border-radius: 12px; }
    .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1 style="margin:0 0 12px; font-size: 22px;">TestAI</h1>
    <div class="top">
      <input id="date" type="date" value="<?= htmlspecialchars($date) ?>">
      <button class="btn" id="btnGet" type="button" title="Показать кешированный HTML-анонс для выбранной даты (если он уже был сгенерирован).">Показать анонс (кеш)</button>
      <button class="btn secondary" id="btnGen" type="button" title="Сгенерировать новый HTML-анонс через Gemini и сохранить его в кеш.">Сгенерировать анонс</button>
      <button class="btn secondary" id="btnDaily" type="button" title="Показать саммари дня (если оно уже было сформировано daily.php).">Саммари дня</button>
    </div>
    <div class="status" id="status"></div>
    <div class="hint" id="stats"></div>
    <div class="box">
      <div class="row" style="justify-content: space-between;">
        <div style="font-weight: 900;">Промт бота</div>
        <button class="btn secondary" id="btnSavePrompt" type="button" title="Сохранить промт бота в БД. Он будет прикрепляться к каждому ответу в Telegram.">Сохранить промт</button>
      </div>
      <div class="hint">Используется для ответов Telegram-бота. Для сохранения может потребоваться ключ.</div>
      <textarea id="prompt" placeholder="Например: Ты SMM ресторана. Отвечай кратко. Формат: HTML для Telegram."></textarea>
    </div>
    <div class="box out" id="out"></div>
  </div>

  <script>
    const qs = (k) => document.getElementById(k)
    const out = qs('out')
    const statusEl = qs('status')
    const statsEl = qs('stats')
    const dateEl = qs('date')
    const promptEl = qs('prompt')
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
    const setStats = (t) => { statsEl.textContent = t || '' }

    const loadPrompt = async () => {
      const d = dateEl.value || ''
      const res = await fetch(mkUrl('get_prompt', d), { headers: { 'Accept': 'application/json' } }).catch(() => null)
      const j = res ? await res.json().catch(() => null) : null
      if (!j || !j.ok) return
      promptEl.value = String(j.prompt || '')
    }

    const savePrompt = async () => {
      setStatus('Сохранение промта…')
      const u = new URL(location.href)
      u.pathname = '/testai/api.php'
      u.searchParams.set('ajax', 'set_prompt')
      u.searchParams.set('date', dateEl.value || '')
      if (adminKey && initialKey) u.searchParams.set('key', initialKey)
      const fd = new FormData()
      fd.set('prompt', promptEl.value || '')
      const res = await fetch(u.toString(), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } }).catch(() => null)
      const j = res ? await res.json().catch(() => null) : null
      if (!j || !j.ok) { setStatus('Не удалось сохранить'); return }
      setStatus('Промт сохранён')
      setTimeout(() => setStatus(''), 1200)
    }

    const refreshStats = async () => {
      const d = dateEl.value || ''
      const res = await fetch(mkUrl('stats', d), { headers: { 'Accept': 'application/json' } }).catch(() => null)
      const j = res ? await res.json().catch(() => null) : null
      if (!j || !j.ok) { setStats(''); return }
      const parts = []
      parts.push(`Записей за день: ${Number(j.count || 0)}`)
      const wm = Number(j.with_media || 0)
      const wmt = Number(j.with_media_text || 0)
      if (wm || wmt) parts.push(`медиа: ${wm}, распознано: ${wmt}`)
      setStats(parts.join(' · '))
    }

    const load = async () => {
      const d = dateEl.value || ''
      setStatus('Загрузка кеша…')
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

    const daily = async () => {
      const d = dateEl.value || ''
      setStatus('Чтение саммари…')
      const res = await fetch(mkUrl('summary', d), { headers: { 'Accept': 'application/json' } }).catch(() => null)
      const j = res ? await res.json().catch(() => null) : null
      if (!j || !j.ok) { setStatus('Ошибка'); render(''); return }
      setStatus('')
      if (!j.exists) { render('<div>Саммари за этот день ещё нет. Запусти /testai/daily?day=' + d + '</div>'); return }
      let html = ''
      const st = String(j.summary_text || '').trim()
      const ev = String(j.events_json || '').trim()
      if (st) html += '<h2>Саммари</h2><div>' + st.replaceAll('\n', '<br>') + '</div>'
      if (ev && ev !== '[]') html += '<h3>Events JSON</h3><pre style="white-space:pre-wrap; margin:0; font-size:12px; color:rgba(255,255,255,0.78)">' + ev.replaceAll('<','&lt;') + '</pre>'
      render(html || '<div>Саммари пустое</div>')
    }

    qs('btnGet').addEventListener('click', load)
    qs('btnGen').addEventListener('click', gen)
    qs('btnDaily').addEventListener('click', daily)
    qs('btnSavePrompt').addEventListener('click', savePrompt)

    dateEl.addEventListener('change', () => { refreshStats(); load() })
    refreshStats()
    load()
    loadPrompt()
  </script>
</body>
</html>
