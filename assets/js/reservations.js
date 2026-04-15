(() => {
  const q = (sel, root = document) => root.querySelector(sel);
  const qa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const bindSort = () => {
    const table = q('.res-table');
    const tbody = table ? q('tbody', table) : null;
    if (!table || !tbody) return;

    const parseDateTime = (s) => {
      const m = String(s || '').match(/(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2})/);
      if (!m) return 0;
      const d = new Date(Number(m[3]), Number(m[2]) - 1, Number(m[1]), Number(m[4]), Number(m[5]), 0, 0);
      return d.getTime() || 0;
    };
    const getCellText = (row, idx) => {
      const td = row && row.children ? row.children[idx] : null;
      return td ? String(td.textContent || '').trim() : '';
    };
    const toNum = (s) => {
      const n = Number(String(s || '').replace(/[^\d.-]+/g, ''));
      return isFinite(n) ? n : 0;
    };

    const headers = qa('th[data-sort]', table);
    headers.forEach((th) => {
      if (!th.dataset.baseLabel) th.dataset.baseLabel = String(th.textContent || '').replace(/[↑↓]/g, '').trim();
    });

    const applyHeaderArrows = (sort, order) => {
      headers.forEach((th) => {
        const base = String(th.dataset.baseLabel || th.textContent || '').replace(/[↑↓]/g, '').trim();
        const key = String(th.dataset.sort || '');
        if (key === sort) th.textContent = base + (order === 'asc' ? ' ↑' : ' ↓');
        else th.textContent = base;
      });
    };

    const sortRows = (sort, order) => {
      const rows = qa('tr', tbody).filter((r) => r.querySelector('td'));
      const withKey = rows.map((r, i) => ({ r, i }));

      const colIdx = (() => {
        const map = { id: 0, qr_code: 1, created_at: 2, start_time: 3, table_num: 4, guests: 5, name: 6, total_amount: 7 };
        return map[sort] != null ? map[sort] : 3;
      })();

      const cmp = (a, b) => {
        const ta = getCellText(a.r, colIdx);
        const tb = getCellText(b.r, colIdx);
        let va = ta, vb = tb;
        if (sort === 'created_at' || sort === 'start_time') { va = parseDateTime(ta); vb = parseDateTime(tb); }
        if (sort === 'id' || sort === 'guests' || sort === 'total_amount' || sort === 'table_num') { va = toNum(ta); vb = toNum(tb); }
        if (va < vb) return order === 'asc' ? -1 : 1;
        if (va > vb) return order === 'asc' ? 1 : -1;
        return a.i - b.i;
      };

      withKey.sort(cmp);
      withKey.forEach(({ r }) => tbody.appendChild(r));
    };

    const url = new URL(location.href);
    let currentSort = url.searchParams.get('sort') || 'start_time';
    let currentOrder = url.searchParams.get('order') || 'desc';
    applyHeaderArrows(currentSort, currentOrder);

    headers.forEach((th) => {
      th.addEventListener('click', () => {
        const sort = String(th.dataset.sort || '');
        if (!sort) return;
        if (currentSort === sort) currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
        else { currentSort = sort; currentOrder = 'desc'; }
        sortRows(currentSort, currentOrder);
        applyHeaderArrows(currentSort, currentOrder);

        const next = new URL(location.href);
        next.searchParams.set('sort', currentSort);
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
          const { res, j } = await postForm('reservations.php?ajax=resend', { id, target });
          if (res.ok && j && j.ok) {
            const parts = [];
            if (target === 'manager') {
              parts.push(j.group_ok ? 'Менеджер: ОК' : 'Менеджер: Ошибка');
            } else if (target === 'guest') {
              if (j.has_tg) parts.push(j.guest_ok ? 'Гость: ОК' : 'Гость: Ошибка');
              else parts.push('Гость: нет TG');
            } else {
              parts.push(j.group_ok ? 'Менеджер: ОК' : 'Менеджер: Ошибка');
              if (j.has_tg) parts.push(j.guest_ok ? 'Гость: ОК' : 'Гость: Ошибка');
              else parts.push('Гость: нет TG');
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
          const { res, j } = await postForm('reservations.php?ajax=toggle_deleted', { id, deleted: isDeleted ? '0' : '1' });
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

  bindSort();
  bindResend();
  bindDelete();
  bindShowDeleted();
})();
