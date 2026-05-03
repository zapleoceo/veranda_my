(() => {
  const hero = document.querySelector('.links-hero')
  const spotlight = document.querySelector('.links-spotlight')
  if (!hero || !spotlight) return

  const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reduced) return

  let rect = hero.getBoundingClientRect()
  const clamp = (v, min, max) => Math.max(min, Math.min(max, v))

  let x = rect.width * 0.62
  let y = rect.height * 0.28
  let targetX = x
  let targetY = y
  let baseX = x
  let baseY = y

  let lastMoveTs = performance.now()
  let idle = false
  let autoT = 0
  let autoPhi = 0
  let prevTs = performance.now()

  const setTargetFromClient = (clientX, clientY) => {
    rect = hero.getBoundingClientRect()
    targetX = clamp(clientX - rect.left, 0, rect.width)
    targetY = clamp(clientY - rect.top, 0, rect.height)
    baseX = targetX
    baseY = targetY
    lastMoveTs = performance.now()
  }

  window.addEventListener('pointermove', (e) => setTargetFromClient(e.clientX, e.clientY), { passive: true })
  window.addEventListener('pointerdown', (e) => setTargetFromClient(e.clientX, e.clientY), { passive: true })
  window.addEventListener('touchmove', (e) => {
    const t = e.touches && e.touches[0]
    if (t) setTargetFromClient(t.clientX, t.clientY)
  }, { passive: true })

  window.addEventListener('resize', () => {
    rect = hero.getBoundingClientRect()
    x = clamp(x, 0, rect.width)
    y = clamp(y, 0, rect.height)
    targetX = clamp(targetX, 0, rect.width)
    targetY = clamp(targetY, 0, rect.height)
    baseX = clamp(baseX, 0, rect.width)
    baseY = clamp(baseY, 0, rect.height)
  }, { passive: true })

  const tick = (ts) => {
    const dt = ts - prevTs
    prevTs = ts

    const idleDelay = 900
    const idleNow = (ts - lastMoveTs) > idleDelay

    if (idleNow) {
      if (!idle) {
        idle = true
        rect = hero.getBoundingClientRect()
        const ux = clamp((baseX / Math.max(1, rect.width)) * 2 - 1, -1, 1)
        const uy = clamp((baseY / Math.max(1, rect.height)) * 2 - 1, -1, 1)
        const t0 = Math.asin(ux)
        const phi0 = Math.asin(uy) - t0 * 0.73
        autoT = Number.isFinite(t0) ? t0 : 0
        autoPhi = Number.isFinite(phi0) ? phi0 : 0
      }

      rect = hero.getBoundingClientRect()
      autoT += dt * 0.00045
      x = ((Math.sin(autoT) + 1) / 2) * rect.width
      y = ((Math.sin(autoT * 0.73 + autoPhi) + 1) / 2) * rect.height
    } else {
      idle = false
      x += (targetX - x) * 0.22
      y += (targetY - y) * 0.22
    }

    x = clamp(x, 0, rect.width)
    y = clamp(y, 0, rect.height)
    hero.style.setProperty('--mx', `${x}px`)
    hero.style.setProperty('--my', `${y}px`)
    requestAnimationFrame(tick)
  }

  hero.style.setProperty('--mx', `${x}px`)
  hero.style.setProperty('--my', `${y}px`)
  requestAnimationFrame(tick)
})()

(() => {
  const panelLinks = Array.from(document.querySelectorAll('.lang-panel a'))
  if (!panelLinks.length) return

  const i18n = window.LINKS_I18N
  if (!i18n) return

  const root = document.documentElement
  const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  const knownLangs = Object.keys(i18n)
  const titleByLang = window.LINKS_TITLE || {}
  const metaByLang = window.LINKS_META || {}

  const clampLang = (v) => knownLangs.includes(v) ? v : (knownLangs.includes('ru') ? 'ru' : knownLangs[0])

  const cookieSet = (name, value) => {
    const maxAge = 31536000
    document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(value)}; Path=/; Max-Age=${maxAge}; SameSite=Lax`
  }

  const qsUpdate = (lang) => {
    const u = new URL(window.location.href)
    u.searchParams.set('lang', lang)
    window.history.replaceState({}, '', u.toString())
    const canonical = document.querySelector('link[rel="canonical"]')
    if (canonical) canonical.setAttribute('href', u.origin + u.pathname + '?lang=' + encodeURIComponent(lang))
    const ogUrl = document.querySelector('meta[property="og:url"]')
    if (ogUrl) ogUrl.setAttribute('content', u.origin + u.pathname + '?lang=' + encodeURIComponent(lang))
  }

  const metaUpdate = (lang) => {
    const title = titleByLang[lang]
    const md = metaByLang[lang]
    if (typeof title === 'string') document.title = title
    const ogt = document.querySelector('meta[property="og:title"]')
    if (ogt && typeof title === 'string') ogt.setAttribute('content', title)
    const twt = document.querySelector('meta[name="twitter:title"]')
    if (twt && typeof title === 'string') twt.setAttribute('content', title)

    const desc = document.querySelector('meta[name="description"]')
    if (desc && typeof md === 'string') desc.setAttribute('content', md)
    const og = document.querySelector('meta[property="og:description"]')
    if (og && typeof md === 'string') og.setAttribute('content', md)
    const twd = document.querySelector('meta[name="twitter:description"]')
    if (twd && typeof md === 'string') twd.setAttribute('content', md)
  }

  const swapText = (el, next) => {
    if (!el) return
    if (el.dataset.i18nBusy === '1') return
    const current = (el.textContent || '').trim()
    const target = (next || '').trim()
    if (current === target) return
    if (reduced) { el.textContent = target; return }

    el.dataset.i18nBusy = '1'
    const cs = window.getComputedStyle(el)
    const isInline = cs.display.startsWith('inline')
    const prevDisplay = el.style.display
    const prevOverflow = el.style.overflow
    const prevAlign = el.style.alignItems

    el.classList.add('i18n-xfade')
    el.style.display = isInline ? 'inline-grid' : 'grid'
    el.style.alignItems = 'start'
    el.style.overflow = 'hidden'

    const oldSpan = document.createElement('span')
    oldSpan.className = 'i18n-layer i18n-layer--old'
    oldSpan.textContent = current

    const newSpan = document.createElement('span')
    newSpan.className = 'i18n-layer i18n-layer--new'
    newSpan.textContent = target

    el.replaceChildren(oldSpan, newSpan)
    el.getBoundingClientRect()
    el.classList.add('is-animating')

    window.setTimeout(() => {
      el.classList.remove('is-animating')
      el.classList.remove('i18n-xfade')
      el.style.display = prevDisplay
      el.style.overflow = prevOverflow
      el.style.alignItems = prevAlign
      el.textContent = target
      delete el.dataset.i18nBusy
    }, 240)
  }

  const applyLang = (lang) => {
    const nextLang = clampLang(lang)
    const data = i18n[nextLang]
    if (!data) return

    root.setAttribute('lang', nextLang)
    window.LINKS_LANG = nextLang
    cookieSet('links_lang', nextLang)
    qsUpdate(nextLang)
    metaUpdate(nextLang)

    for (const a of panelLinks) {
      const href = a.getAttribute('href') || ''
      const m = href.match(/[?&]lang=([a-zA-Z-]+)/)
      const code = m ? m[1].toLowerCase() : ''
      a.classList.toggle('active', code === nextLang)
    }

    const primaryBtns = document.querySelectorAll('.primary-btn[data-key]')
    for (const btn of primaryBtns) {
      const key = btn.getAttribute('data-key') || ''
      const tr = data.items && data.items[key]
      if (!tr) continue
      const titleEl = btn.querySelector('.primary-title')
      swapText(titleEl, tr.title || '')
      const subEl = btn.querySelector('.primary-sub')
      swapText(subEl, tr.subtitle || '')
    }

    const cards = document.querySelectorAll('.card[data-key]')
    for (const card of cards) {
      const key = card.getAttribute('data-key') || ''
      const tr = data.items && data.items[key]
      if (!tr) continue
      const titleEl = card.querySelector('.title')
      swapText(titleEl, tr.title || '')
      const subEl = card.querySelector('.sub')
      swapText(subEl, tr.subtitle || '')
    }

    const hoursTitleEl = document.querySelector('.hours__title')
    if (hoursTitleEl) swapText(hoursTitleEl, (data.hours && data.hours.title) || '')
    const lineEls = Array.from(document.querySelectorAll('.hours__lines > div'))
    if (lineEls[0]) swapText(lineEls[0], (data.hours && data.hours.line1) || '')
    if (lineEls[1]) swapText(lineEls[1], (data.hours && data.hours.line2) || '')
  }

  for (const a of panelLinks) {
    a.addEventListener('click', (e) => {
      if (a.classList.contains('active')) return
      e.preventDefault()
      const details = a.closest('details')
      if (details) details.open = false
      const href = a.getAttribute('href') || ''
      const m = href.match(/[?&]lang=([a-zA-Z-]+)/)
      const nextLang = m ? m[1].toLowerCase() : ''
      applyLang(nextLang)
    })
  }
})()
