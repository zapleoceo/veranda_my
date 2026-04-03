(() => {
  const fire = () => window.dispatchEvent(new Event('resize'));
  const kick = () => {
    requestAnimationFrame(() => {
      fire();
      requestAnimationFrame(fire);
    });
    setTimeout(fire, 200);
    setTimeout(fire, 800);
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
})();
