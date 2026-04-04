(() => {
  const fire = () => {
    try { window.dispatchEvent(new Event('resize')); } catch (_) {}
  };
  const kick = () => {
    try {
      requestAnimationFrame(() => {
        fire();
        requestAnimationFrame(fire);
      });
    } catch (_) {
      fire();
    }
    setTimeout(fire, 80);
    setTimeout(fire, 200);
    setTimeout(fire, 450);
    setTimeout(fire, 800);
    let n = 0;
    const raf = () => {
      n++;
      fire();
      if (n < 20) requestAnimationFrame(raf);
    };
    try { requestAnimationFrame(raf); } catch (_) {}
  };
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', kick, { once: true });
  } else {
    kick();
  }
  window.addEventListener('load', () => {
    fire();
    setTimeout(fire, 300);
  });
  window.addEventListener('pageshow', kick);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') kick();
  });
  try {
    if (window.ResizeObserver) {
      const ro = new ResizeObserver(() => fire());
      ro.observe(document.documentElement);
    }
  } catch (_) {}
  try {
    if (window.visualViewport) {
      window.visualViewport.addEventListener('resize', fire);
    }
  } catch (_) {}
})();
