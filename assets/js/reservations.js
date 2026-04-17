(() => {
  const q = (sel, root = document) => root.querySelector(sel);
  const qa = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const endpoint = (() => {
    const p = String(location.pathname || '').replace(/\/+$/, '');
    return p === '' ? '/reservations' : p;
  })();

  const bindSort = () => {
    const table = q('#resTable') || q('.res-table');
    const tbody = table ? q('tbody', table) : null;
    if (!table || !tbody) return;

    const headers = qa('thead .res-head-cols th[data-col]', table);
    const sortable = headers.filter((th) => !!th.dataset.sort);
    sortable.forEach((th) => {
      if (!th.dataset.baseLabel) th.dataset.baseLabel = String(th.textContent || '').trim();
    });

    const parseVal = (td, type) => {
      const raw = td ? (td.dataset.sortValue != null ? String(td.dataset.sortValue) : String(td.textContent || '').trim()) : '';
      if (type === 'num') {
        const n = Number(String(raw).replace(/[^\d.-]+/g, ''));
        return isFinite(n) ? n : 0;
      }
      if (type === 'date') {
        const n = Number(raw);
        return isFinite(n) ? n : 0;
      }
      return String(raw || '').toLowerCase();
    };

    const applyHeaderArrows = (col, order) => {
      sortable.forEach((th) => {
        const base = String(th.dataset.baseLabel || th.textContent || '').trim();
        const k = String(th.dataset.col || '');
        if (k === col) th.textContent = base + (order === 'asc' ? ' ↑' : ' ↓');
        else th.textContent = base;
      });
    };

    const sortRows = (col, type, order) => {
      const rows = qa('tr', tbody).filter((r) => r.querySelector('td'));
      const withKey = rows.map((r, i) => ({ r, i }));
      withKey.sort((a, b) => {
        const ta = a.r.querySelector(`td[data-col="${CSS.escape(col)}"]`);
        const tb = b.r.querySelector(`td[data-col="${CSS.escape(col)}"]`);
        const va = parseVal(ta, type);
        const vb = parseVal(tb, type);
        if (va < vb) return order === 'asc' ? -1 : 1;
        if (va > vb) return order === 'asc' ? 1 : -1;
        return a.i - b.i;
      });
      withKey.forEach(({ r }) => tbody.appendChild(r));
    };

    const url = new URL(location.href);
    let currentCol = url.searchParams.get('col') || 'start_time';
    let currentOrder = url.searchParams.get('order') || 'desc';
    const initialTh = sortable.find((th) => String(th.dataset.col || '') === currentCol) || sortable.find((th) => String(th.dataset.sort || '') === currentCol);
    if (initialTh) {
      const col = String(initialTh.dataset.col || '');
      const type = String(initialTh.dataset.type || 'text');
      sortRows(col, type, currentOrder);
      applyHeaderArrows(col, currentOrder);
      currentCol = col;
    } else if (sortable[0]) {
      currentCol = String(sortable[0].dataset.col || '');
      applyHeaderArrows(currentCol, currentOrder);
    }

    sortable.forEach((th) => {
      th.addEventListener('click', () => {
        const col = String(th.dataset.col || '');
        const type = String(th.dataset.type || 'text');
        if (!col) return;
        if (currentCol === col) currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
        else { currentCol = col; currentOrder = 'desc'; }
        sortRows(currentCol, type, currentOrder);
        applyHeaderArrows(currentCol, currentOrder);

        const next = new URL(location.href);
        next.searchParams.set('col', currentCol);
        next.searchParams.set('order', currentOrder);
        history.replaceState({}, '', next.toString());
      });
    });
  };

  const postForm = async (url, data) => {
    const fd = new FormData();
    Object.keys(data || {}).forEach((k) => fd.append(k, String(data[k])));
    const res = await fetch(url, { method: 'POST', body: fd });
    const j = await res.json().catch(() => null);
    return { res, j };
  };

  const bindResend = () => {
    qa('.btn-resend').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const target = String(btn.dataset.target || 'both');
        const msg = target === 'guest'
          ? 'Отправить сообщение гостю повторно?'
          : (target === 'manager'
            ? 'Отправить сообщение менеджеру (в группу) повторно?'
            : 'Отправить сообщение в группу и гостю повторно?');
        if (!confirm(msg)) return;
        const id = String(btn.dataset.id || '');
        if (!id) return;
        const statusEl = q(`#resend-status-${id}`);
        btn.disabled = true;
        if (statusEl) {
          statusEl.textContent = 'Отправка…';
          statusEl.style.color = 'var(--muted)';
        }
        try {
          const { res, j } = await postForm(endpoint + '?ajax=resend', { id, target });
          if (res.ok && j && j.ok) {
            const parts = [];
            const guestChannel = String(j.guest_channel || (j.has_tg ? 'telegram' : '')).toLowerCase();
            if (target === 'manager') {
              parts.push(j.group_ok ? 'Менеджер: ОК' : 'Менеджер: Ошибка');
            } else if (target === 'guest') {
              if (guestChannel === 'telegram') parts.push(j.guest_ok ? 'Гость: ОК (TG)' : 'Гость: Ошибка (TG)');
              else if (guestChannel === 'whatsapp') parts.push(j.guest_ok ? 'Гость: ОК (WA)' : 'Гость: Ошибка (WA)');
              else parts.push('Гость: нет контакта');
            } else {
              parts.push(j.group_ok ? 'Менеджер: ОК' : 'Менеджер: Ошибка');
              if (guestChannel === 'telegram') parts.push(j.guest_ok ? 'Гость: ОК (TG)' : 'Гость: Ошибка (TG)');
              else if (guestChannel === 'whatsapp') parts.push(j.guest_ok ? 'Гость: ОК (WA)' : 'Гость: Ошибка (WA)');
              else parts.push('Гость: нет контакта');
            }
            if (statusEl) {
              statusEl.textContent = parts.join(' | ');
              statusEl.style.color = '#81c784';
            }
          } else {
            if (statusEl) {
              statusEl.textContent = 'Ошибка: ' + (j && j.error ? String(j.error) : 'Network error');
              statusEl.style.color = '#e57373';
            }
          }
        } catch (e) {
          if (statusEl) {
            statusEl.textContent = 'Ошибка запроса';
            statusEl.style.color = '#e57373';
          }
        } finally {
          btn.disabled = false;
        }
      });
    });
  };

  const setRowDeletedState = (row, deleted, deletedBy, deletedAt) => {
    if (!row) return;
    row.classList.toggle('is-deleted', !!deleted);
    const id = String(row.dataset.id || '');
    const tag = q(`#deleted-tag-${id}`);
    const btn = q(`.btn-delete[data-id="${CSS.escape(id)}"]`);
    if (tag) {
      if (deleted) {
        const by = deletedBy ? (' · ' + deletedBy) : '';
        tag.textContent = 'Удалено' + by;
        tag.classList.add('tag', 'deleted');
        tag.hidden = false;
      } else {
        tag.hidden = true;
      }
    }
    if (btn) btn.textContent = deleted ? 'Восстановить' : 'Удалить';
    const meta = q(`#deleted-meta-${id}`);
    if (meta) {
      if (deleted && deletedAt) {
        meta.textContent = deletedAt;
        meta.hidden = false;
      } else {
        meta.hidden = true;
      }
    }
  };

  const bindDelete = () => {
    qa('.btn-delete').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const id = String(btn.dataset.id || '');
        if (!id) return;
        const row = q(`tr[data-id="${CSS.escape(id)}"]`);
        const isDeleted = row ? row.classList.contains('is-deleted') : false;
        const ok = confirm(isDeleted ? 'Восстановить запись?' : 'Пометить бронь как удалённую?');
        if (!ok) return;
        btn.disabled = true;
        try {
          const { res, j } = await postForm(endpoint + '?ajax=toggle_deleted', { id, deleted: isDeleted ? '0' : '1' });
          if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
          setRowDeletedState(row, !!j.deleted, String(j.deleted_by || ''), String(j.deleted_at || ''));
        } catch (e) {
          alert(String(e && e.message ? e.message : e));
        } finally {
          btn.disabled = false;
        }
      });
    });
  };

  const bindShowDeleted = () => {
    const cb = q('#showDeleted');
    const pcb = q('#showPoster');
    if (!cb && !pcb) return;

    const reload = () => {
      const url = new URL(location.href);
      if (cb && cb.checked) url.searchParams.set('show_deleted', '1');
      else url.searchParams.delete('show_deleted');

      if (pcb && !pcb.checked) url.searchParams.set('show_poster', '0');
      else url.searchParams.delete('show_poster');

      location.href = url.toString();
    };

    if (cb) cb.addEventListener('change', reload);
    if (pcb) pcb.addEventListener('change', reload);
  };

  const bindVPoster = () => {
    const modal = q('#vposterModal');
    const check = q('#vposterConfirmCheck');
    const btnOk = q('#vposterOk');
    const btnCancel = q('#vposterCancel');
    const info = q('#vposterModalInfo');
    if (!modal || !check || !btnOk || !btnCancel) return;

    let activeId = null;
    let activeBtn = null;
    let inFlight = false;

    const closeModal = () => {
      modal.hidden = true;
      check.checked = false;
      btnOk.disabled = true;
      activeId = null;
      activeBtn = null;
      inFlight = false;
      if (info) info.textContent = '';
    };

    check.addEventListener('change', () => {
      btnOk.disabled = !check.checked;
    });

    btnCancel.addEventListener('click', closeModal);

    btnOk.addEventListener('click', async () => {
      if (!activeId || !activeBtn || inFlight) return;
      const id = activeId;
      const btn = activeBtn;
      inFlight = true;
      closeModal();

      const statusEl = q(`#resend-status-${id}`);
      btn.disabled = true;
      if (statusEl) {
        statusEl.textContent = 'Создание в Poster…';
        statusEl.style.color = 'var(--muted)';
      }
      
      try {
        const { res, j } = await postForm(endpoint + '?ajax=vposter', { id });
        if (res.ok && j && j.ok) {
          if (statusEl) {
            statusEl.textContent = j.duplicate ? 'В Poster: уже было ✅' : 'В Poster: создано ✅';
            statusEl.style.color = '#81c784';
          }
          btn.remove(); // Remove button after success
        } else {
          if (statusEl) {
            statusEl.textContent = 'Ошибка Poster: ' + (j && j.error ? String(j.error) : 'Network error');
            statusEl.style.color = '#e57373';
          }
          btn.disabled = false;
        }
      } catch (e) {
        if (statusEl) {
          statusEl.textContent = 'Ошибка запроса';
          statusEl.style.color = '#e57373';
        }
        btn.disabled = false;
      }
    });

    qa('.btn-vposter').forEach((btn) => {
      btn.addEventListener('click', () => {
        activeId = String(btn.dataset.id || '');
        activeBtn = btn;
        if (!activeId) return;
        if (info) {
          const code = String(btn.dataset.code || '').trim();
          const start = String(btn.dataset.start || '').trim();
          const table = String(btn.dataset.table || '').trim();
          const guests = String(btn.dataset.guests || '').trim();
          const name = String(btn.dataset.name || '').trim();
          const phone = String(btn.dataset.phone || '').trim();
          const parts = [];
          if (code) parts.push('Код: ' + code);
          if (start) parts.push('Время: ' + start);
          if (table) parts.push('Стол: ' + table);
          if (guests) parts.push('Гости: ' + guests);
          if (name) parts.push('Имя: ' + name);
          if (phone) parts.push('Телефон: ' + phone);
          info.textContent = parts.join(' · ');
        }
        modal.hidden = false;
      });
    });
  };

  const bindColumns = () => {
    const table = q('#resTable') || q('.res-table');
    const wrap = q('#resTableWrap') || q('.table-wrap');
    const groupRow = table ? q('thead .res-head-group', table) : null;
    const colRow = table ? q('thead .res-head-cols', table) : null;
    const btn = q('#resColBtn');
    const panel = q('#resColPanel');
    if (!table || !wrap || !groupRow || !colRow || !btn || !panel) return;

    const ths = qa('th[data-col]', colRow);
    const cols = ths.map((th) => ({
      col: String(th.dataset.col || ''),
      label: String(th.dataset.baseLabel || th.textContent || '').trim(),
      side: String(th.dataset.side || 'site'),
    })).filter((c) => c.col !== '');

    const key = 'res_cols_hidden_v1';
    const hidden = (() => {
      try {
        const v = JSON.parse(localStorage.getItem(key) || '[]');
        return Array.isArray(v) ? v.map(String) : [];
      } catch {
        return [];
      }
    })();

    const setVisible = (col, visible) => {
      const list = qa(`[data-col="${CSS.escape(col)}"]`, table);
      list.forEach((el) => {
        el.style.display = visible ? '' : 'none';
      });
    };

    const applyAll = () => {
      cols.forEach((c) => setVisible(c.col, !hidden.includes(c.col)));

      const visibleCols = cols.filter((c) => !hidden.includes(c.col));
      const siteCount = visibleCols.filter((c) => c.side !== 'poster').length;
      const posterCount = visibleCols.filter((c) => c.side === 'poster').length;
      const siteTh = q('th[data-side="site"]', groupRow);
      const posterTh = q('th[data-side="poster"]', groupRow);
      if (siteTh) siteTh.colSpan = Math.max(1, siteCount);
      if (posterTh) posterTh.colSpan = Math.max(1, posterCount);

      const empty = q('tbody .res-empty td', table);
      if (empty) empty.colSpan = Math.max(1, visibleCols.length);
    };

    panel.textContent = '';
    cols.forEach((c) => {
      const row = document.createElement('label');
      row.className = 'res-colrow';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.checked = !hidden.includes(c.col);
      cb.addEventListener('change', () => {
        const idx = hidden.indexOf(c.col);
        if (cb.checked) {
          if (idx !== -1) hidden.splice(idx, 1);
        } else {
          if (idx === -1) hidden.push(c.col);
        }
        localStorage.setItem(key, JSON.stringify(hidden));
        applyAll();
        window.dispatchEvent(new Event('resize'));
      });
      const lbl = document.createElement('span');
      lbl.className = 'lbl';
      lbl.textContent = c.label;
      const side = document.createElement('span');
      side.className = 'side';
      side.textContent = c.side === 'poster' ? 'Poster' : 'Сайт';
      row.appendChild(cb);
      row.appendChild(lbl);
      row.appendChild(side);
      panel.appendChild(row);
    });

    const close = () => { panel.hidden = true; };
    const open = () => { panel.hidden = false; };
    btn.addEventListener('click', () => {
      panel.hidden ? open() : close();
    });
    document.addEventListener('click', (e) => {
      const t = e.target;
      if (!t) return;
      if (panel.hidden) return;
      if (panel.contains(t) || btn.contains(t)) return;
      close();
    });

    applyAll();
  };

  const bindLayout = () => {
    const wrap = q('#resTableWrap') || q('.table-wrap');
    const table = q('#resTable') || q('.res-table');
    const hscroll = q('#resHScroll');
    const hinner = q('#resHScrollInner');
    if (!wrap || !table || !hscroll || !hinner) return;

    let syncing = false;
    const updateHScroll = () => {
      const sw = table.scrollWidth || 0;
      const cw = wrap.clientWidth || 0;
      const need = sw > cw + 2;
      hscroll.hidden = !need;
      document.body.classList.toggle('res-hscroll-on', need);
      if (!need) return;
      hinner.style.width = sw + 'px';
      if (!syncing) hscroll.scrollLeft = wrap.scrollLeft;
    };

    const updateWrapHeight = () => {
      const rect = wrap.getBoundingClientRect();
      const barH = !hscroll.hidden ? hscroll.offsetHeight : 0;
      const maxH = Math.floor(window.innerHeight - rect.top - barH - 12);
      if (maxH > 240) wrap.style.maxHeight = maxH + 'px';
    };

    const updateStickyOffsets = () => {
      const group = q('thead .res-head-group', table);
      if (!group) return;
      const h = Math.ceil(group.getBoundingClientRect().height || 0);
      if (h > 0) document.documentElement.style.setProperty('--resHead1H', h + 'px');
    };

    hscroll.addEventListener('scroll', () => {
      if (syncing) return;
      syncing = true;
      wrap.scrollLeft = hscroll.scrollLeft;
      syncing = false;
    });
    wrap.addEventListener('scroll', () => {
      if (syncing) return;
      syncing = true;
      hscroll.scrollLeft = wrap.scrollLeft;
      syncing = false;
    });

    const onResize = () => {
      updateStickyOffsets();
      updateHScroll();
      updateWrapHeight();
    };

    window.addEventListener('resize', onResize);
    onResize();
    setTimeout(onResize, 50);
  };

  bindSort();
  bindResend();
  bindVPoster();
  bindDelete();
  bindShowDeleted();
  bindColumns();
  bindLayout();
})();
