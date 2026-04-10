(() => {
  const bg = document.querySelector('.parallax-bg');
  if (!bg) return;
  const speed = 0.22;
  let ticking = false;
  const update = () => {
    const y = window.pageYOffset || 0;
    bg.style.transform = `translate3d(0, ${-y * speed}px, 0) scale(1.12)`;
    ticking = false;
  };
  window.addEventListener('scroll', () => {
    if (ticking) return;
    window.requestAnimationFrame(update);
    ticking = true;
  }, { passive: true });
  update();
})();
