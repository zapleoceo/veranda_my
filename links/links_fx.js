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
  let driftT = 0
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
        driftT = 0
      }

      driftT += dt
      rect = hero.getBoundingClientRect()
      const ampX = Math.min(110, rect.width * 0.06)
      const ampY = Math.min(90, rect.height * 0.05)
      const t = driftT * 0.00084
      x = baseX + Math.sin(t) * ampX
      y = baseY + Math.sin(t * 0.83) * ampY
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
