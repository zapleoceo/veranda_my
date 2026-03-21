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

(() => {
  const menu = document.querySelector('.user-menu');
  if (!menu) return;
  let t = null;
  const open = () => {
    if (t) {
      clearTimeout(t);
      t = null;
    }
    menu.classList.add('open');
  };
  const scheduleClose = () => {
    if (t) clearTimeout(t);
    t = setTimeout(() => {
      menu.classList.remove('open');
      t = null;
    }, 700);
  };
  menu.addEventListener('mouseenter', open);
  menu.addEventListener('mouseleave', scheduleClose);
})();

