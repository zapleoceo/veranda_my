(() => {
  const q = (sel, root = document) => root.querySelector(sel);
  const endpoint = (() => {
    const p = String(location.pathname || '').replace(/\/+$/, '');
    return p === '' ? '/reservations' : p;
  })();

  const postForm = async (url, data) => {
    const fd = new FormData();
    Object.keys(data || {}).forEach((k) => fd.append(k, String(data[k])));
    const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
    const j = await res.json().catch(() => null);
    return { res, j };
  };

  const bindHall = () => {
    const section = q('#resHallSection');
    const board = q('#resHallBoard');
    if (!section || !board) return;
    if (section.dataset.boundHall === '1') return;
    section.dataset.boundHall = '1';

    const spotIdInput = q('#resSpotId');
    const hallIdInput = q('#resHallId');
    const soonInput = q('#resSoonHours');
    const minPreorderInput = q('#resMinPreorderPerGuest');
    const btnApply = q('#resHallApply');
    const btnRotate = q('#resHallRotate');
    const btnAll = q('#resHallAll');
    const btnNone = q('#resHallNone');
    const emptyEl = q('#resHallEmpty');

    const modal = q('#resHallModal');
    const mNum = q('#resHallModalNum');
    const mCap = q('#resHallModalCap');
    const mAllowed = q('#resHallModalAllowed');
    const mCancel = q('#resHallModalCancel');
    const mSave = q('#resHallModalSave');

    const parseInlineData = () => {
      try {
        const raw = section.dataset && section.dataset.hallData ? String(section.dataset.hallData) : '';
        if (!raw) return null;
        const j = JSON.parse(raw);
        return j && typeof j === 'object' ? j : null;
      } catch (_) {
        return null;
      }
    };

    const state = (() => {
      const data = parseInlineData() || {};
      return {
        rot: false,
        active: null,
        tables: Array.isArray(data.tables) ? data.tables.slice() : [],
        spotId: Number(data.spot_id || 1) || 1,
        hallId: Number(data.hall_id || 2) || 2,
      };
    })();

    const setEmpty = (text) => {
      if (!emptyEl) return;
      const msg = String(text || '').trim();
      emptyEl.hidden = msg === '';
      emptyEl.textContent = msg;
    };

    const closeModal = () => {
      if (modal) modal.hidden = true;
      state.active = null;
    };
    if (mCancel) mCancel.addEventListener('click', closeModal);

    const computeLayout = () => {
      const W = 980, H = 620, pad = 20;
      let minX = null, minY = null, maxX = null, maxY = null;
      state.tables.forEach((t) => {
        const x = Number(t.x || 0) || 0;
        const y = Number(t.y || 0) || 0;
        const w = Number(t.w || 0) || 6;
        const h = Number(t.h || 0) || 6;
        minX = minX == null ? x : Math.min(minX, x);
        minY = minY == null ? y : Math.min(minY, y);
        maxX = maxX == null ? (x + w) : Math.max(maxX, x + w);
        maxY = maxY == null ? (y + h) : Math.max(maxY, y + h);
      });
      minX = minX == null ? 0 : minX;
      minY = minY == null ? 0 : minY;
      maxX = maxX == null ? 1 : maxX;
      maxY = maxY == null ? 1 : maxY;
      const worldW = Math.max(1, maxX - minX);
      const worldH = Math.max(1, maxY - minY);
      const scale = Math.min((W - pad * 2) / worldW, (H - pad * 2) / worldH);
      return { W, H, pad, minX, minY, scale };
    };

    const render = () => {
      const { W, H, pad, minX, minY, scale } = computeLayout();
      board.style.width = W + 'px';
      board.style.height = H + 'px';
      board.textContent = '';
      if (!state.tables.length) {
        setEmpty('Нет данных по столам (проверь spot_id/hall_id и Poster токен)');
        return;
      }
      setEmpty('');

      state.tables.forEach((t) => {
        const x = Number(t.x || 0) || 0;
        const y = Number(t.y || 0) || 0;
        const w0 = Number(t.w || 0) || 6;
        const h0 = Number(t.h || 0) || 6;
        const w = w0 * scale;
        const h = h0 * scale;
        let left = pad + (x - minX) * scale;
        let top = pad + (y - minY) * scale;
        if (state.rot) {
          left = W - left - w;
          top = H - top - h;
        }

        const el = document.createElement('div');
        el.className = 'res-hall-tbl' + (String(t.shape || '') === 'circle' ? ' circle' : '') + (!t.scheme_num ? ' disabled' : '');
        el.style.left = left + 'px';
        el.style.top = top + 'px';
        el.style.width = w + 'px';
        el.style.height = h + 'px';

        const row = document.createElement('div');
        row.className = 'row';
        const num = document.createElement('div');
        num.className = 'num';
        num.textContent = t.scheme_num ? String(t.scheme_num) : (t.table_num ? String(t.table_num) : ('#' + String(t.table_id || '')));
        const cap = document.createElement('div');
        cap.className = 'cap';
        cap.textContent = t.scheme_num ? ('👤' + String(t.cap || 0)) : '—';
        const cb = document.createElement('input');
        cb.className = 'chk';
        cb.type = 'checkbox';
        cb.checked = !!t.is_allowed;
        cb.disabled = !t.scheme_num;
        cb.addEventListener('click', (e) => e.stopPropagation());
        cb.addEventListener('change', async () => {
          if (!t.scheme_num) return;
          t.is_allowed = cb.checked ? 1 : 0;
          await postForm(endpoint + '?ajax=res_table_update', { hall_id: state.hallId, spot_id: state.spotId, scheme_num: t.scheme_num, allowed: cb.checked ? 1 : 0, cap: t.cap || 0 });
        });

        row.appendChild(num);
        row.appendChild(cap);
        row.appendChild(cb);
        el.appendChild(row);

        const title = document.createElement('div');
        title.className = 'title';
        title.textContent = String(t.table_title || '').trim();
        el.appendChild(title);

        el.addEventListener('click', () => {
          if (!t.scheme_num) return;
          state.active = t;
          if (mNum) mNum.textContent = String(t.scheme_num);
          if (mCap) mCap.value = String(t.cap || 0);
          if (mAllowed) mAllowed.checked = !!t.is_allowed;
          if (modal) modal.hidden = false;
        });

        board.appendChild(el);
      });
    };

    const loadHall = async (spotId, hallId) => {
      const url = new URL(endpoint, location.origin);
      url.searchParams.set('ajax', 'res_hall_data');
      url.searchParams.set('spot_id', String(spotId));
      url.searchParams.set('hall_id', String(hallId));
      const res = await fetch(url.toString(), { credentials: 'same-origin' });
      const j = await res.json().catch(() => null);
      if (!res.ok || !j || !j.ok) throw new Error(j && j.error ? j.error : 'Ошибка загрузки');
      state.tables = Array.isArray(j.tables) ? j.tables.slice() : [];
      state.spotId = Number(j.spot_id || spotId) || spotId;
      state.hallId = Number(j.hall_id || hallId) || hallId;
      if (spotIdInput) spotIdInput.value = String(state.spotId);
      if (hallIdInput) hallIdInput.value = String(state.hallId);
      if (soonInput) soonInput.value = String(Number(j.soon_hours || 2) || 2);
      if (minPreorderInput) minPreorderInput.value = String(Number(j.min_preorder_per_guest || minPreorderInput.value || 0) || 0);
      const u = new URL(location.href);
      u.searchParams.set('spot_id', String(state.spotId));
      u.searchParams.set('hall_id', String(state.hallId));
      history.replaceState({}, '', u.toString());
      render();
      window.dispatchEvent(new Event('resize'));
    };

    if (btnApply) btnApply.addEventListener('click', async () => {
      const s = spotIdInput ? (Number(spotIdInput.value || 1) || 1) : state.spotId;
      const h = hallIdInput ? (Number(hallIdInput.value || 2) || 2) : state.hallId;
      await loadHall(s, h);
    });
    if (btnRotate) btnRotate.addEventListener('click', () => { state.rot = !state.rot; render(); });

    if (btnAll) btnAll.addEventListener('click', async () => {
      const todo = state.tables.filter((t) => t.scheme_num && !t.is_allowed);
      for (const t of todo) {
        t.is_allowed = 1;
        await postForm(endpoint + '?ajax=res_table_update', { hall_id: state.hallId, spot_id: state.spotId, scheme_num: t.scheme_num, allowed: 1, cap: t.cap || 0 });
      }
      render();
    });
    if (btnNone) btnNone.addEventListener('click', async () => {
      const todo = state.tables.filter((t) => t.scheme_num && t.is_allowed);
      for (const t of todo) {
        t.is_allowed = 0;
        await postForm(endpoint + '?ajax=res_table_update', { hall_id: state.hallId, spot_id: state.spotId, scheme_num: t.scheme_num, allowed: 0, cap: t.cap || 0 });
      }
      render();
    });

    if (mSave) mSave.addEventListener('click', async () => {
      if (!state.active) return;
      const t = state.active;
      const cap = mCap ? (Number(mCap.value || 0) || 0) : 0;
      const allowed = mAllowed ? !!mAllowed.checked : false;
      t.cap = cap;
      t.is_allowed = allowed ? 1 : 0;
      await postForm(endpoint + '?ajax=res_table_update', { hall_id: state.hallId, spot_id: state.spotId, scheme_num: t.scheme_num, allowed: allowed ? 1 : 0, cap });
      closeModal();
      render();
    });

    if (soonInput) soonInput.addEventListener('change', async () => {
      const v = Number(soonInput.value || 2) || 2;
      await postForm(endpoint + '?ajax=res_soon_hours', { soon_hours: v });
    });

    if (minPreorderInput) minPreorderInput.addEventListener('change', async () => {
      const v = Math.max(0, Math.floor(Number(minPreorderInput.value || 0) || 0));
      const { res, j } = await postForm(endpoint + '?ajax=res_preorder_min_per_guest', { min_per_guest: v });
      if (res.ok && j && j.ok) minPreorderInput.value = String(Number(j.min_per_guest || v) || v);
    });

    render();
    window.addEventListener('resize', () => render());

    if (!state.tables.length) {
      loadHall(state.spotId, state.hallId).catch((e) => setEmpty(String(e && e.message ? e.message : e)));
    }
  };

  bindHall();
})();

