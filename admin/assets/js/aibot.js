/* aibot.js — admin UI for the AI bot */
(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const adminBase = window.location.pathname.replace(/\/[^/]*$/, '');
  const api = (params) => adminBase + '?tab=aibot&' + new URLSearchParams(params);

  // ── Helpers ──────────────────────────────────────────────────────────────

  function setPill(id, ok, text) {
    const el = $(id);
    if (!el) return;
    el.className = 'pill ' + (ok ? 'ok' : 'err');
    if (text !== undefined) el.textContent = text;
  }

  function savedAt(id, ts) {
    const el = $(id);
    if (el && ts) el.textContent = 'Сохранено: ' + ts;
  }

  async function post(action, body) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(body)) fd.append(k, String(v));
    const r = await fetch(api({ ajax: action }), { method: 'POST', body: fd });
    return r.json();
  }

  async function get(action, extra = {}) {
    return (await fetch(api({ ajax: action, ...extra }))).json();
  }

  function htmlEsc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function date() { return $('aibotDate')?.value || ''; }

  // ── Status ───────────────────────────────────────────────────────────────

  async function loadState() {
    const d = await get('state', { date: date() });
    setPill('pillDb',     d.db_ok,          d.db_ok ? 'DB ✓' : 'DB ✗');
    setPill('pillGemini', d.gemini_can_call, d.gemini_can_call ? 'AI ✓' : 'AI ✗');
    const meta = $('aibotMeta');
    if (meta) meta.textContent = d.gemini_model
      ? ('Модель: ' + d.gemini_model + '  ·  Сообщений в БД: ' + (d.raw_total || 0))
      : '';
    const lp = $('logFilePath');
    if (lp && d.log_file) lp.textContent = d.log_file;
  }

  $('btnRefreshState')?.addEventListener('click', loadState);
  $('aibotDate')?.addEventListener('change', loadState);
  loadState();

  // ── Bot test ──────────────────────────────────────────────────────────────

  async function runBotTest() {
    const q = ($('botTestQ')?.value ?? '').trim();
    if (!q) return;
    const res = $('botTestResult');
    if (res) { res.style.display = 'block'; res.innerHTML = '<em>Думает...</em>'; }
    const d = await post('bot_test', { question: q });
    if (res) res.innerHTML = d.ok
      ? d.html
      : ('<span style="color:#c00">Ошибка: ' + htmlEsc(d.error || '?') + '</span>');
  }

  $('btnBotTest')?.addEventListener('click', runBotTest);
  $('botTestQ')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') runBotTest(); });

  // ── Bot identity ──────────────────────────────────────────────────────────

  $('btnIdentitySave')?.addEventListener('click', async () => {
    const d = await post('identity_save', { value: $('botIdentity')?.value ?? '' });
    if (d.ok) savedAt('identitySavedAt', d.updated_at);
    else alert('Ошибка: ' + (d.error || '?'));
  });

  // ── Forbidden topics ──────────────────────────────────────────────────────

  $('btnForbiddenSave')?.addEventListener('click', async () => {
    const d = await post('forbidden_save', { value: $('botForbidden')?.value ?? '' });
    if (d.ok) savedAt('forbiddenSavedAt', d.updated_at);
    else alert('Ошибка: ' + (d.error || '?'));
  });

  // ── Logs ─────────────────────────────────────────────────────────────────

  $('btnLogTail')?.addEventListener('click', async () => {
    const out = $('logOutput');
    if (!out) return;
    out.style.display = 'block';
    out.textContent = 'Загрузка...';
    const d = await get('log_tail', { n: 100 });
    out.textContent = d.tail || '(пусто)';
    out.scrollTop = out.scrollHeight;
  });

  // ── Operations ────────────────────────────────────────────────────────────

  function showOpsResult(html) {
    const el = $('opsOutput');
    if (el) el.innerHTML = html;
  }

  $('btnAnnounceGet')?.addEventListener('click', async () => {
    const d = await get('announce_get', { date: date() });
    showOpsResult(d.html || '<em>Кеш пуст</em>');
  });

  $('btnAnnounceGen')?.addEventListener('click', async () => {
    showOpsResult('<em>Генерация...</em>');
    const d = await get('announce_generate', { date: date() });
    showOpsResult(d.html || '<em>Ошибка генерации</em>');
  });

  $('btnDailyGet')?.addEventListener('click', async () => {
    const d = await get('daily_get', { date: date() });
    showOpsResult(d.exists
      ? ('<b>Саммари за ' + date() + '</b><br><pre style="white-space:pre-wrap">' + htmlEsc(d.summary_text) + '</pre>')
      : '<em>Саммари не найдено</em>');
  });

  $('btnDailyRun')?.addEventListener('click', async () => {
    showOpsResult('<em>Генерация...</em>');
    const d = await post('daily_run', { date: date() });
    showOpsResult(d.ok
      ? ('<b>Готово</b><br><pre style="white-space:pre-wrap">' + htmlEsc(d.summary_text) + '</pre>')
      : '<em>Ошибка генерации</em>');
  });

  // ── KB table ──────────────────────────────────────────────────────────────

  const ACCESS_LABEL = { public: 'Для всех', members: 'Только своим', never: 'Скрыто' };
  const ACCESS_CLASS = { public: 'pill-public', members: 'pill-members', never: 'pill-never' };

  async function reloadKb() {
    const d = await get('kb_list');
    if (!d.ok) return;
    const tbody = $('kbBody');
    if (!tbody) return;
    if (!d.items.length) {
      tbody.innerHTML = '<tr><td colspan="5" style="padding:16px 10px; color:#999; text-align:center;">База знаний пуста. Добавьте документ или импортируйте страницу по URL.</td></tr>';
      return;
    }
    tbody.innerHTML = d.items.map(it => `
      <tr data-id="${it.id}">
        <td style="padding:8px 10px">${htmlEsc(it.title)}</td>
        <td style="padding:8px 10px; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap">
          ${it.source_url ? `<a href="${htmlEsc(it.source_url)}" target="_blank" style="font-size:12px">🔗 URL</a>` : '<span class="small-muted">текст</span>'}
        </td>
        <td style="padding:8px 10px">
          <span class="pill ${ACCESS_CLASS[it.access] || ''}">${ACCESS_LABEL[it.access] || it.access}</span>
        </td>
        <td style="padding:8px 10px">${it.is_active ? '<span style="color:#2e7d32">●</span>' : '<span style="color:#999">○</span>'}</td>
        <td style="padding:8px 10px; white-space:nowrap">
          <button class="btn btn-sm btn-kb-edit" data-id="${it.id}">✏</button>
          <button class="btn btn-sm btn-kb-del" data-id="${it.id}" style="color:#c00">✕</button>
        </td>
      </tr>`).join('');
    attachKbListeners();
  }

  function attachKbListeners() {
    document.querySelectorAll('.btn-kb-edit').forEach(btn => {
      btn.addEventListener('click', () => openKbModal(+btn.dataset.id));
    });
    document.querySelectorAll('.btn-kb-del').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Удалить документ?')) return;
        const d = await post('kb_delete', { id: btn.dataset.id });
        if (d.ok) reloadKb(); else alert('Ошибка удаления');
      });
    });
  }
  attachKbListeners();

  // ── KB modal ──────────────────────────────────────────────────────────────

  function openKbModal(id) {
    $('kbId').value             = id || '';
    $('kbTitle').value          = '';
    $('kbUrl').value            = '';
    $('kbContent').value        = '';
    $('kbAccess').value         = 'public';
    $('kbActive').checked       = true;
    $('kbModalErr').textContent = '';
    $('kbModalTitle').textContent = id ? 'Редактировать документ' : 'Новый документ';
    $('kbModal').style.display  = 'flex';

    if (id) {
      get('kb_get', { id }).then(d => {
        if (!d.ok || !d.item) return;
        const it = d.item;
        $('kbTitle').value    = it.title || '';
        $('kbUrl').value      = it.source_url || '';
        $('kbContent').value  = it.content || '';
        $('kbAccess').value   = it.access || 'public';
        $('kbActive').checked = !!+it.is_active;
      });
    }
  }

  function closeKbModal() { $('kbModal').style.display = 'none'; }

  $('btnKbAdd')?.addEventListener('click', () => openKbModal(0));
  $('btnKbCancel')?.addEventListener('click', closeKbModal);
  $('kbModal')?.addEventListener('click', (e) => { if (e.target === $('kbModal')) closeKbModal(); });

  $('btnKbSave')?.addEventListener('click', async () => {
    const id      = +($('kbId')?.value || 0);
    const title   = ($('kbTitle')?.value ?? '').trim();
    const url     = ($('kbUrl')?.value ?? '').trim();
    const content = ($('kbContent')?.value ?? '').trim();
    const access  = $('kbAccess')?.value || 'public';
    const active  = $('kbActive')?.checked ? 1 : 0;

    $('kbModalErr').textContent = '';
    if (!title) { $('kbModalErr').textContent = 'Введите название'; return; }
    if (!content && !url) { $('kbModalErr').textContent = 'Заполните содержимое или URL'; return; }

    const d = await post('kb_save', { id, title, source_url: url, content, access, is_active: active });
    if (d.ok) { closeKbModal(); reloadKb(); }
    else $('kbModalErr').textContent = 'Ошибка: ' + (d.error || '?');
  });

  // ── KB import URL ─────────────────────────────────────────────────────────

  $('btnKbImport')?.addEventListener('click', async () => {
    const url = ($('kbImportUrl')?.value ?? '').trim();
    if (!url) return;
    const btn  = $('btnKbImport');
    const prev = btn.textContent;
    btn.textContent = 'Загрузка...';
    btn.disabled    = true;
    const d = await post('kb_import_url', { url });
    btn.textContent = prev;
    btn.disabled    = false;
    if (d.ok) { $('kbImportUrl').value = ''; reloadKb(); }
    else alert('Ошибка импорта: ' + (d.error || '?'));
  });

})();
