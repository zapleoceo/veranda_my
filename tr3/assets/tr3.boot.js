(() => {
  const lang = (document.documentElement && document.documentElement.lang) ? document.documentElement.lang : 'ru';
  const url = new URL('/tr3/api.php', location.origin);
  url.searchParams.set('ajax', 'bootstrap');
  url.searchParams.set('lang', lang);

  const loadScript = (src) => new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    s.defer = true;
    s.onload = () => resolve();
    s.onerror = () => reject(new Error('Failed to load ' + src));
    document.head.appendChild(s);
  });

  fetch(url.toString(), { headers: { 'Accept': 'application/json' } })
    .then((r) => r.json().then((j) => ({ r, j })))
    .then(({ r, j }) => {
      if (!r.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Bootstrap failed');
      window.__TR_CONFIG__ = {
        lang: j.lang,
        locale: j.locale,
        str: j.str,
        i18n_all: j.i18n_all,
        defaultResDateLocal: j.defaultResDateLocal,
        allowedTableNums: j.allowedTableNums,
        tableCapsByNum: j.tableCapsByNum,
        soonBookingHours: j.soonBookingHours,
        apiBase: j.apiBase,
      };
      return loadScript('/tr3/assets/app.js?v=20260416_0712');
    })
    .catch((e) => {
      const msg = document.createElement('div');
      msg.style.cssText = 'position:fixed;left:12px;bottom:12px;z-index:99999;background:#2a1a12;color:#fff;padding:10px 12px;border-radius:10px;font:12px/1.3 system-ui;max-width:90vw;';
      msg.textContent = 'Ошибка загрузки бронирования: ' + String(e && e.message ? e.message : e);
      document.body.appendChild(msg);
    });
})();
