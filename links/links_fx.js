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
  const panelLinks = document.querySelectorAll('.lang-panel a')
  if (!panelLinks.length) return

  const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  const root = document.documentElement

  window.addEventListener('pageshow', () => {
    root.classList.remove('lang-leave')
  }, { passive: true })

  for (const a of panelLinks) {
    a.addEventListener('click', (e) => {
      if (a.classList.contains('active')) return
      if (reduced) return
      e.preventDefault()
      const href = a.href
      const details = a.closest('details')
      if (details) details.open = false
      root.classList.add('lang-leave')
      window.setTimeout(() => { window.location.href = href }, 190)
    })
  }
})()
