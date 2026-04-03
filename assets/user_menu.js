(() => {
  const menus = Array.from(document.querySelectorAll('.user-menu'));
  if (menus.length === 0) return;

  menus.forEach((menu) => {
    const chip = menu.querySelector('.user-chip');
    let t = null;

    const open = () => {
      if (t) {
        clearTimeout(t);
        t = null;
      }
      menu.classList.add('open');
    };

    const close = () => {
      if (t) {
        clearTimeout(t);
        t = null;
      }
      menu.classList.remove('open');
    };

    const scheduleClose = () => {
      if (t) clearTimeout(t);
      t = setTimeout(() => {
        menu.classList.remove('open');
        t = null;
      }, 700);
    };

    if (chip) {
      chip.style.cursor = 'pointer';
      chip.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (menu.classList.contains('open')) close();
        else open();
      });
    }

    menu.addEventListener('mouseenter', open);
    menu.addEventListener('mouseleave', scheduleClose);

    document.addEventListener('click', (e) => {
      if (menu.contains(e.target)) return;
      close();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') close();
    });
  });
})();

