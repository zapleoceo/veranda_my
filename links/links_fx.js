(() => {
  const hero = document.querySelector('.links-hero')
  const spotlight = document.querySelector('.links-spotlight')
  if (!hero || !spotlight) return

  const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reduced) return

  let raf = 0
  let lastX = 0
  let lastY = 0

  const set = () => {
    raf = 0
    hero.style.setProperty('--mx', `${lastX}px`)
    hero.style.setProperty('--my', `${lastY}px`)
  }

  const onMove = (clientX, clientY) => {
    const r = hero.getBoundingClientRect()
    lastX = Math.max(0, Math.min(r.width, clientX - r.left))
    lastY = Math.max(0, Math.min(r.height, clientY - r.top))
    if (!raf) raf = requestAnimationFrame(set)
  }

  window.addEventListener('pointermove', (e) => onMove(e.clientX, e.clientY), { passive: true })
  window.addEventListener('pointerdown', (e) => onMove(e.clientX, e.clientY), { passive: true })
  window.addEventListener('touchmove', (e) => {
    const t = e.touches && e.touches[0]
    if (t) onMove(t.clientX, t.clientY)
  }, { passive: true })

  const r0 = hero.getBoundingClientRect()
  onMove(r0.left + r0.width * 0.62, r0.top + r0.height * 0.28)
})()

