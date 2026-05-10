(() => {
  const $ = (id) => document.getElementById(id)
  const dateEl = $('aibotDate')
  const outBox = $('outBox')
  const outMeta = $('outMeta')
  const metaEl = $('aibotMeta')
  const pillDb = $('pillDb')
  const pillGemini = $('pillGemini')
  const pillDaily = $('pillDaily')
  const promptText = $('promptText')
  const promptMeta = $('promptMeta')
  const baseText = $('baseText')
  const baseMeta = $('baseMeta')
  const chatText = $('chatText')
  const chatMeta = $('chatMeta')
  const dailySysText = $('dailySysText')
  const dailySysMeta = $('dailySysMeta')
  const announceSysText = $('announceSysText')
  const announceSysMeta = $('announceSysMeta')

  const langChat = $('langChat')
  const langDaily = $('langDaily')
  const langAnnounce = $('langAnnounce')

  const mapMeta = $('mapMeta')
  const behaviorJson = $('behaviorJson')
  const behaviorMeta = $('behaviorMeta')

  const agentQuestion = $('agentQuestion')
  const agentChatId = $('agentChatId')
  const agentMeta = $('agentMeta')

  const ctxQuestion = $('ctxQuestion')
  const ctxMode = $('ctxMode')
  const ctxChatId = $('ctxChatId')
  const ctxMeta = $('ctxMeta')

  const kbTbody = $('kbTbody')
  const kbEditor = $('kbEditor')
  const kbId = $('kbId')
  const kbTitle = $('kbTitle')
  const kbTags = $('kbTags')
  const kbSourceUrl = $('kbSourceUrl')
  const kbIsActive = $('kbIsActive')
  const kbContent = $('kbContent')
  const kbEditorMeta = $('kbEditorMeta')
  const kbImportUrl = $('kbImportUrl')

  const qs = (k) => encodeURIComponent(String(k || ''))
  const apiUrl = (ajax) => {
    const d = dateEl && dateEl.value ? dateEl.value : ''
    return `/admin/?tab=aibot&ajax=${qs(ajax)}&date=${qs(d)}`
  }

  const setPill = (el, label, state) => {
    el.textContent = label
    el.classList.remove('ok', 'warn', 'bad')
    if (state === 'ok') el.classList.add('ok')
    else if (state === 'warn') el.classList.add('warn')
    else if (state === 'bad') el.classList.add('bad')
  }

  const renderHtml = (html) => {
    outBox.innerHTML = html || '<div class="muted">Нет данных</div>'
  }

  const renderText = (text) => {
    const esc = (s) => String(s || '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    outBox.innerHTML = `<pre style="margin:0; white-space:pre-wrap;">${esc(text)}</pre>`
  }

  const fetchJson = async (url, opts) => {
    const res = await fetch(url, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, opts || {})).catch(() => null)
    if (!res) return { ok: false, error: 'network' }
    const j = await res.json().catch(() => null)
    if (!j) return { ok: false, error: 'bad_json', http: res.status }
    if (!res.ok) return Object.assign({ ok: false, http: res.status }, j)
    return j
  }

  const state = async () => {
    outMeta.textContent = ''
    const j = await fetchJson(apiUrl('state'))
    if (!j.ok) {
      setPill(pillDb, 'DB', 'bad')
      setPill(pillGemini, 'Gemini', 'bad')
      setPill(pillDaily, 'Daily', 'warn')
      metaEl.textContent = j.error ? String(j.error) : 'Ошибка'
      return
    }
    setPill(pillDb, 'DB', j.db_ok ? 'ok' : 'bad')
    setPill(pillGemini, 'Gemini', j.gemini_can_call ? 'ok' : 'bad')
    setPill(pillDaily, 'Daily', j.daily_exists ? 'ok' : 'warn')
    metaEl.textContent = `raw=${Number(j.raw_total || 0)} · day=${Number(j.day_count || 0)} · media=${Number(j.day_with_media || 0)}`
    promptMeta.textContent = j.prompt_updated_at ? `Обновлено: ${String(j.prompt_updated_at)}` : ''
    baseMeta.textContent = j.system_base_updated_at ? `Обновлено: ${String(j.system_base_updated_at)}` : ''
    chatMeta.textContent = j.system_chat_updated_at ? `Обновлено: ${String(j.system_chat_updated_at)}` : ''
    dailySysMeta.textContent = j.system_daily_updated_at ? `Обновлено: ${String(j.system_daily_updated_at)}` : ''
    announceSysMeta.textContent = j.system_announce_updated_at ? `Обновлено: ${String(j.system_announce_updated_at)}` : ''
    if (mapMeta) mapMeta.textContent = j.instr_map_updated_at ? `Обновлено: ${String(j.instr_map_updated_at)}` : ''
    if (behaviorMeta) behaviorMeta.textContent = j.behavior_updated_at ? `Обновлено: ${String(j.behavior_updated_at)}` : ''
    if (langChat && j.lang_chat) langChat.value = String(j.lang_chat)
    if (langDaily && j.lang_daily) langDaily.value = String(j.lang_daily)
    if (langAnnounce && j.lang_announce) langAnnounce.value = String(j.lang_announce)
    if (j.gemini_proxy_base) outMeta.textContent = `Proxy: ${String(j.gemini_proxy_base)} · Model: ${String(j.gemini_model || '')}`
  }

  const announceGet = async () => {
    outMeta.textContent = 'Анонс (кеш)'
    const j = await fetchJson(apiUrl('announce_get'))
    if (!j.ok) return renderText(j.error || 'Ошибка')
    renderHtml(j.html || '')
  }

  const announceGen = async () => {
    outMeta.textContent = 'Генерация анонса…'
    const j = await fetchJson(apiUrl('announce_generate'))
    if (!j.ok) return renderText(j.error || 'Ошибка')
    renderHtml(j.html || '')
  }

  const dailyGet = async () => {
    outMeta.textContent = 'Саммари (кеш)'
    const j = await fetchJson(apiUrl('daily_get'))
    if (!j.ok) return renderText(j.error || 'Ошибка')
    if (!j.exists) return renderText('Саммари за этот день ещё нет.')
    const parts = []
    if (j.summary_text) parts.push(`SUMMARY:\n${j.summary_text}`)
    if (j.events_json && j.events_json !== '[]') parts.push(`\nEVENTS:\n${j.events_json}`)
    renderText(parts.join('\n\n') || 'Пусто')
  }

  const dailyRun = async () => {
    outMeta.textContent = 'Генерация саммари…'
    const j = await fetchJson(apiUrl('daily_run'))
    if (!j.ok) return renderText(j.error || 'Ошибка')
    if (!j.exists) return renderText('Не получилось сформировать саммари.')
    const parts = []
    if (j.summary_text) parts.push(`SUMMARY:\n${j.summary_text}`)
    if (j.events_json && j.events_json !== '[]') parts.push(`\nEVENTS:\n${j.events_json}`)
    renderText(parts.join('\n\n') || 'Пусто')
  }

  const promptSave = async () => {
    const fd = new FormData()
    fd.set('prompt', promptText.value || '')
    const j = await fetchJson(apiUrl('prompt_save'), { method: 'POST', body: fd })
    if (!j.ok) return
    await state()
  }

  const settingSave = async (key, value) => {
    const fd = new FormData()
    fd.set('key', String(key || ''))
    fd.set('value', String(value || ''))
    const j = await fetchJson(apiUrl('setting_save'), { method: 'POST', body: fd })
    if (!j.ok) return false
    return true
  }

  const baseSave = async () => {
    const ok = await settingSave('bot_system_base', baseText.value || '')
    if (!ok) return
    await state()
  }

  const chatSave = async () => {
    const ok1 = await settingSave('bot_system_chat', chatText.value || '')
    const ok2 = await settingSave('bot_lang_chat', langChat && langChat.value ? langChat.value : 'auto')
    if (!ok1 || !ok2) return
    await state()
  }

  const dailySysSave = async () => {
    const ok1 = await settingSave('bot_system_daily', dailySysText.value || '')
    const ok2 = await settingSave('bot_lang_daily', langDaily && langDaily.value ? langDaily.value : 'ru')
    if (!ok1 || !ok2) return
    await state()
  }

  const announceSysSave = async () => {
    const ok1 = await settingSave('bot_system_announce', announceSysText.value || '')
    const ok2 = await settingSave('bot_lang_announce', langAnnounce && langAnnounce.value ? langAnnounce.value : 'ru')
    if (!ok1 || !ok2) return
    await state()
  }

  const behaviorSave = async () => {
    if (!behaviorJson) return
    const raw = String(behaviorJson.value || '')
    try {
      if (raw.trim() !== '') JSON.parse(raw)
    } catch (e) {
      renderText('Ошибка JSON: ' + String(e && e.message ? e.message : e))
      return
    }
    const ok = await settingSave('bot_behavior_json', raw)
    if (!ok) return
    await state()
  }

  const agentAsk = async () => {
    const q = (agentQuestion && agentQuestion.value ? agentQuestion.value : '').trim()
    if (!q) return
    if (agentMeta) agentMeta.textContent = 'Запрос…'
    outMeta.textContent = 'Ответ'
    const fd = new FormData()
    fd.set('question', q)
    const cid = (agentChatId && agentChatId.value ? agentChatId.value : '').trim()
    if (cid) fd.set('chat_id', cid)
    const j = await fetchJson(apiUrl('agent_test'), { method: 'POST', body: fd })
    if (!j.ok) {
      if (agentMeta) agentMeta.textContent = ''
      return renderText(j.error || 'Ошибка')
    }
    if (agentMeta) agentMeta.textContent = `lang=${String(j.lang || '')} · system_len=${Number(j.system_len || 0)}`
    const esc = (s) => String(s || '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    const trace = j.trace ? esc(JSON.stringify(j.trace, null, 2)) : ''
    const traceBox = trace ? `<details style="margin-top:10px;"><summary style="cursor:pointer; font-weight:800;">Trace</summary><pre style="margin:0; white-space:pre-wrap;">${trace}</pre></details>` : ''
    outBox.innerHTML = `<div>${j.html || ''}</div>${traceBox}`
  }

  const mapSave = async () => {
    const inputs = Array.from(document.querySelectorAll('input[data-map][data-block]'))
    const map = { chat: {}, daily: {}, announce: {} }
    inputs.forEach((el) => {
      const m = String(el.getAttribute('data-map') || '')
      const b = String(el.getAttribute('data-block') || '')
      if (!m || !b || !map[m]) return
      map[m][b] = el.checked ? 1 : 0
    })
    const ok = await settingSave('bot_instr_map', JSON.stringify(map))
    if (!ok) return
    await state()
  }

  const ctxPreview = async () => {
    const q = (ctxQuestion.value || '').trim()
    if (!q) return
    ctxMeta.textContent = 'Сбор контекста…'
    outMeta.textContent = 'Контекст'
    const fd = new FormData()
    fd.set('question', q)
    fd.set('mode', (ctxMode && ctxMode.value ? ctxMode.value : 'chat'))
    const chatId = (ctxChatId.value || '').trim()
    if (chatId) fd.set('chat_id', chatId)
    const j = await fetchJson(apiUrl('context_preview'), { method: 'POST', body: fd })
    if (!j.ok) {
      ctxMeta.textContent = ''
      return renderText(j.error || 'Ошибка')
    }
    ctxMeta.textContent = `docs=${Number(j.knowledge_docs_count || 0)} · ctx=${Number(j.context_count || 0)} · system_len=${Number(j.system_len || 0)}`
    renderText(JSON.stringify(j, null, 2))
  }

  const logTail = async () => {
    outMeta.textContent = 'Логи'
    const j = await fetchJson(`${apiUrl('log_tail')}&n=160`)
    if (!j.ok) return renderText(j.error || 'Ошибка')
    const head = j.file ? `FILE: ${String(j.file)}\n\n` : ''
    renderText(head + String(j.tail || ''))
  }

  const kbSetEditorVisible = (on) => {
    kbEditor.hidden = !on
    kbEditorMeta.textContent = ''
  }

  const kbFill = (item) => {
    kbId.value = item && item.id ? String(item.id) : ''
    kbTitle.value = item && item.title ? String(item.title) : ''
    kbTags.value = item && item.tags ? String(item.tags) : ''
    kbSourceUrl.value = item && item.source_url ? String(item.source_url) : ''
    kbIsActive.checked = item ? (Number(item.is_active || 0) === 1) : true
    kbContent.value = item && item.content ? String(item.content) : ''
  }

  const kbNew = () => {
    kbFill(null)
    kbSetEditorVisible(true)
  }

  const kbRowHtml = (it) => {
    const esc = (s) => String(s || '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    return `
      <tr data-id="${Number(it.id || 0)}">
        <td style="padding:8px 10px; white-space:nowrap;">${Number(it.id || 0)}</td>
        <td style="padding:8px 10px;">${esc(it.title || '')}</td>
        <td style="padding:8px 10px; white-space:nowrap;">${esc(it.tags || '')}</td>
        <td style="padding:8px 10px;">${esc(it.source_url || '')}</td>
        <td style="padding:8px 10px; white-space:nowrap;">${esc(it.updated_at || '')}</td>
        <td style="padding:8px 10px; white-space:nowrap;">
          <button class="btn" type="button" data-act="edit">Edit</button>
          <button class="btn btn-danger" type="button" data-act="del">Del</button>
        </td>
      </tr>
    `
  }

  const kbRefresh = async () => {
    const j = await fetchJson(apiUrl('kb_list'))
    if (!j.ok) return
    const items = Array.isArray(j.items) ? j.items : []
    kbTbody.innerHTML = items.map(kbRowHtml).join('')
  }

  const kbLoad = async (id) => {
    const j = await fetchJson(`/admin/?tab=aibot&ajax=kb_get&id=${qs(id)}`)
    if (!j.ok || !j.item) return
    kbFill(j.item)
    kbEditorMeta.textContent = j.item.updated_at ? `Обновлено: ${String(j.item.updated_at)}` : ''
    kbSetEditorVisible(true)
  }

  const kbSave = async () => {
    const fd = new FormData()
    if (kbId.value) fd.set('id', kbId.value)
    fd.set('title', kbTitle.value || '')
    fd.set('tags', kbTags.value || '')
    fd.set('source_url', kbSourceUrl.value || '')
    fd.set('content', kbContent.value || '')
    fd.set('is_active', kbIsActive.checked ? '1' : '0')
    const j = await fetchJson(`/admin/?tab=aibot&ajax=kb_save`, { method: 'POST', body: fd })
    if (!j.ok) {
      kbEditorMeta.textContent = j.error ? String(j.error) : 'Ошибка сохранения'
      return
    }
    await kbRefresh()
    kbLoad(j.id || 0)
  }

  const kbDelete = async (id) => {
    if (!confirm('Удалить запись?')) return
    const fd = new FormData()
    fd.set('id', String(id))
    const j = await fetchJson(`/admin/?tab=aibot&ajax=kb_delete`, { method: 'POST', body: fd })
    if (!j.ok) return
    kbSetEditorVisible(false)
    await kbRefresh()
  }

  const kbImport = async () => {
    const url = (kbImportUrl.value || '').trim()
    if (!url) return
    const fd = new FormData()
    fd.set('url', url)
    const j = await fetchJson(`/admin/?tab=aibot&ajax=kb_import_url`, { method: 'POST', body: fd })
    if (!j.ok) {
      outMeta.textContent = ''
      renderText(j.error ? String(j.error) : 'Ошибка импорта')
      return
    }
    kbImportUrl.value = ''
    await kbRefresh()
    if (j.id) await kbLoad(j.id)
  }

  const on = (id, ev, fn) => { const el = $(id); if (el) el.addEventListener(ev, fn) }

  on('btnState', 'click', state)
  on('btnLogTail', 'click', logTail)
  on('btnAnnounceGet', 'click', announceGet)
  on('btnAnnounceGen', 'click', announceGen)
  on('btnDailyGet', 'click', dailyGet)
  on('btnDailyRun', 'click', dailyRun)
  on('btnPromptSave', 'click', promptSave)
  on('btnBaseSave', 'click', baseSave)
  on('btnChatSave', 'click', chatSave)
  on('btnDailySysSave', 'click', dailySysSave)
  on('btnAnnounceSysSave', 'click', announceSysSave)
  on('btnMapSave', 'click', mapSave)
  on('btnBehaviorSave', 'click', behaviorSave)
  on('btnAgentAsk', 'click', agentAsk)
  on('btnCtxPreview', 'click', ctxPreview)

  on('btnKbNew', 'click', kbNew)
  on('btnKbRefresh', 'click', kbRefresh)
  on('btnKbSave', 'click', kbSave)
  on('btnKbCancel', 'click', () => kbSetEditorVisible(false))
  on('btnKbImport', 'click', kbImport)

  kbTbody.addEventListener('click', (e) => {
    const btn = e.target && e.target.closest ? e.target.closest('button[data-act]') : null
    if (!btn) return
    const tr = btn.closest('tr')
    if (!tr) return
    const id = Number(tr.getAttribute('data-id') || 0) || 0
    const act = btn.getAttribute('data-act')
    if (act === 'edit') kbLoad(id)
    if (act === 'del') kbDelete(id)
  })

  if (dateEl) {
    dateEl.addEventListener('change', async () => {
      await state()
      await announceGet()
    })
  }

  kbSetEditorVisible(false)
  state()
})()
