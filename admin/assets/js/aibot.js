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

  $('btnState').addEventListener('click', state)
  $('btnAnnounceGet').addEventListener('click', announceGet)
  $('btnAnnounceGen').addEventListener('click', announceGen)
  $('btnDailyGet').addEventListener('click', dailyGet)
  $('btnDailyRun').addEventListener('click', dailyRun)
  $('btnPromptSave').addEventListener('click', promptSave)

  $('btnKbNew').addEventListener('click', kbNew)
  $('btnKbRefresh').addEventListener('click', kbRefresh)
  $('btnKbSave').addEventListener('click', kbSave)
  $('btnKbCancel').addEventListener('click', () => kbSetEditorVisible(false))
  $('btnKbImport').addEventListener('click', kbImport)

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

  dateEl.addEventListener('change', async () => {
    await state()
    await announceGet()
  })

  kbSetEditorVisible(false)
  state()
})()

