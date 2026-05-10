/* aibot.js */
(function () {
  'use strict';

  const $ = (id) => document.getElementById(id);
  const adminBase = window.location.pathname.replace(/\/[^/]*$/, '');
  const api = (params) => adminBase + '?tab=aibot&' + new URLSearchParams(params);

  async function post(action, body) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(body)) fd.append(k, String(v));
    return (await fetch(api({ ajax: action }), { method: 'POST', body: fd })).json();
  }
  async function get(action, extra = {}) {
    return (await fetch(api({ ajax: action, ...extra }))).json();
  }

  function htmlEsc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function date() { return $('aibotDate')?.value || ''; }
  function ts(id, v) { const el = $(id); if (el && v) el.textContent = 'Сохранено: ' + v; }

  // ── Status ──────────────────────────────────────────────────────────────

  async function loadState() {
    const d = await get('state', { date: date() });
    const db = $('pillDb'), ai = $('pillGemini'), block = $('pillBlock'), br = $('btnBlockReset'), meta = $('aibotMeta'), lp = $('logFilePath');
    if (db)   { db.className = 'ai-pill ' + (d.db_ok ? 'ok' : 'err'); db.textContent = d.db_ok ? 'DB ✓' : 'DB ✗'; }
    const rem = Math.max(0, parseInt(d.block_remaining || 0, 10) || 0);
    if (ai) {
      const can = !!d.gemini_can_call;
      const ready = (parseInt(d.gemini_ready || 0, 10) || 0) === 1;
      if (!can) { ai.className = 'ai-pill err'; ai.textContent = 'AI ✗'; }
      else if (!ready || rem > 0) { ai.className = 'ai-pill err'; ai.textContent = 'AI ⏳'; }
      else { ai.className = 'ai-pill ok'; ai.textContent = 'AI ✓'; }
    }
    if (block) {
      if (rem > 0) { block.style.display = ''; block.textContent = '⊘ ' + rem + 'с'; }
      else block.style.display = 'none';
    }
    if (br) br.style.display = rem > 0 ? '' : 'none';
    if (meta) {
      const parts = [];
      if (d.gemini_model) parts.push(d.gemini_model);
      parts.push((d.raw_total || 0) + ' сообщ.');
      if (rem > 0) parts.push('cooldown ' + rem + 'с');
      meta.textContent = parts.join(' · ');
    }
    if (lp && d.log_file) lp.textContent = d.log_file;
  }

  $('btnRefreshState')?.addEventListener('click', loadState);
  $('btnBlockReset')?.addEventListener('click', async () => {
    const btn = $('btnBlockReset');
    if (btn) btn.disabled = true;
    try { await post('block_reset', {}); } catch (e) {}
    if (btn) btn.disabled = false;
    loadState();
  });
  $('aibotDate')?.addEventListener('change', loadState);
  loadState();

  // ── Bot test ──────────────────────────────────────────────────────────────

  async function runBotTest() {
    const q = ($('botTestQ')?.value ?? '').trim();
    if (!q) return;
    const res = $('botTestResult');
    if (res) { res.style.display = 'block'; res.innerHTML = '<em>Думает...</em>'; }
    const d = await post('bot_test', { question: q });
    if (res) res.innerHTML = d.ok ? d.html : '<span style="color:#c00">Ошибка: ' + htmlEsc(d.error || '?') + '</span>';
  }

  $('btnBotTest')?.addEventListener('click', runBotTest);
  $('botTestQ')?.addEventListener('keydown', (e) => { if (e.key === 'Enter') runBotTest(); });

  // ── Settings ──────────────────────────────────────────────────────────────

  $('btnIdentitySave')?.addEventListener('click', async () => {
    const d = await post('identity_save', { value: $('botIdentity')?.value ?? '' });
    if (d.ok) ts('identitySavedAt', d.updated_at); else alert('Ошибка: ' + (d.error || '?'));
  });

  $('btnForbiddenSave')?.addEventListener('click', async () => {
    const d = await post('forbidden_save', { value: $('botForbidden')?.value ?? '' });
    if (d.ok) ts('forbiddenSavedAt', d.updated_at); else alert('Ошибка: ' + (d.error || '?'));
  });

  // ── Poster ────────────────────────────────────────────────────────────────

  async function loadPosterStatus() {
    const d = await get('poster_status');
    if (!d.ok || !d.configured) { const el = $('posterStatus'); if (el) el.textContent = 'POSTER_API_TOKEN не настроен'; return; }
    const el = $('posterStatus'); if (el) el.textContent = d.text || '';
    const upd = $('posterUpdatedAt'); if (upd && d.updated_at) upd.textContent = 'Обновлено: ' + d.updated_at;
  }

  $('btnPosterRefresh')?.addEventListener('click', async () => {
    const btn = $('btnPosterRefresh');
    if (btn) { btn.disabled = true; btn.textContent = '...'; }
    const d = await get('poster_refresh');
    if (btn) { btn.disabled = false; btn.textContent = '↻ Обновить'; }
    const el = $('posterStatus'); if (el && d.ok) el.textContent = d.text || '';
    const upd = $('posterUpdatedAt'); if (upd && d.ok) upd.textContent = 'Обновлено: ' + d.updated_at;
  });

  // Load poster status when service section opens
  $('ai-root')?.closest('.card')?.parentElement?.addEventListener('click', () => {});
  document.querySelector('.ai-service')?.addEventListener('toggle', loadPosterStatus, { once: true });

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

  function showOps(html) { const el = $('opsOutput'); if (el) el.innerHTML = html; }

  $('btnAnnounceGet')?.addEventListener('click', async () => showOps((await get('announce_get', { date: date() })).html || '<em>Кеш пуст</em>'));
  $('btnAnnounceGen')?.addEventListener('click', async () => { showOps('<em>Генерация...</em>'); showOps((await get('announce_generate', { date: date() })).html || '<em>Ошибка</em>'); });
  $('btnDailyGet')?.addEventListener('click', async () => {
    const d = await get('daily_get', { date: date() });
    showOps(d.exists ? '<b>Саммари за ' + date() + '</b><br><pre style="white-space:pre-wrap">' + htmlEsc(d.summary_text) + '</pre>' : '<em>Нет</em>');
  });
  $('btnDailyRun')?.addEventListener('click', async () => {
    showOps('<em>Генерация...</em>');
    const d = await post('daily_run', { date: date() });
    showOps(d.ok ? '<b>Готово</b><br><pre style="white-space:pre-wrap">' + htmlEsc(d.summary_text) + '</pre>' : '<em>Ошибка</em>');
  });

  // ── KB table (event delegation — works for server-rendered AND dynamic rows) ──

  const ACCESS_LABEL = { public: 'Все', members: 'Своим', never: 'Скрыто' };
  const ACCESS_CLASS = { public: 'ai-acc-public', members: 'ai-acc-members', never: 'ai-acc-never' };

  async function reloadKb() {
    const d = await get('kb_list');
    if (!d.ok) return;
    const tbody = $('kbBody');
    if (!tbody) return;
    if (!d.items.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="ai-empty">Пусто. Добавьте документ или импортируйте URL.</td></tr>';
      return;
    }
    tbody.innerHTML = d.items.map(it => `
      <tr data-id="${it.id}">
        <td>${htmlEsc(it.title)}</td>
        <td>${it.source_url ? `<a href="${htmlEsc(it.source_url)}" target="_blank">🔗</a>` : '<span class="ai-muted">текст</span>'}</td>
        <td><span class="ai-acc ${ACCESS_CLASS[it.access] || ''}">${ACCESS_LABEL[it.access] || it.access}</span></td>
        <td>${it.is_active ? '<span class="ai-dot-on">●</span>' : '<span class="ai-dot-off">○</span>'}</td>
        <td class="ai-actions">
          <button class="ai-btn btn-kb-edit" data-id="${it.id}">✏</button>
          <button class="ai-btn ai-btn-del btn-kb-del" data-id="${it.id}">✕</button>
        </td>
      </tr>`).join('');
  }

  // Delegate clicks on KB table — catches both server-rendered and JS-rendered rows
  document.addEventListener('click', (e) => {
    const editBtn = e.target.closest('.btn-kb-edit');
    if (editBtn) { openKbModal(+editBtn.dataset.id); return; }
    const delBtn = e.target.closest('.btn-kb-del');
    if (delBtn) {
      if (!confirm('Удалить документ?')) return;
      post('kb_delete', { id: delBtn.dataset.id }).then(d => { if (d.ok) reloadKb(); else alert('Ошибка'); });
    }
  });

  // ── KB modal ──────────────────────────────────────────────────────────────

  function openKbModal(id) {
    $('kbId').value             = id || '';
    $('kbTitle').value          = '';
    $('kbUrl').value            = '';
    $('kbContent').value        = '';
    $('kbAccess').value         = 'public';
    $('kbActive').checked       = true;
    $('kbModalErr').textContent = '';
    $('kbModalTitle').textContent = id ? 'Редактировать' : 'Новый документ';
    $('kbModal').style.display  = 'flex';
    if (id) get('kb_get', { id }).then(d => {
      if (!d.ok || !d.item) return;
      const it = d.item;
      $('kbTitle').value    = it.title || '';
      $('kbUrl').value      = it.source_url || '';
      $('kbContent').value  = it.content || '';
      $('kbAccess').value   = it.access || 'public';
      $('kbActive').checked = !!+it.is_active;
    });
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
    const btn = $('btnKbImport');
    btn.disabled = true; btn.textContent = '...';
    const d = await post('kb_import_url', { url });
    btn.disabled = false; btn.textContent = '↗ URL';
    if (d.ok) { $('kbImportUrl').value = ''; reloadKb(); }
    else alert('Ошибка импорта: ' + (d.error || '?'));
  });

})();
