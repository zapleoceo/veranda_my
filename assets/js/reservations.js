(() => {
  const q = (sel, root = document) => root.querySelector(sel);
  const qa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const bindSort = () => {
    qa('th[data-sort]').forEach((th) => {
      th.addEventListener('click', () => {
        const sort = String(th.dataset.sort || '');
        if (!sort) return;
        const url = new URL(location.href);
        const currentSort = url.searchParams.get('sort') || 'start_time';
        let order = url.searchParams.get('order') || 'desc';
        if (currentSort === sort) order = order === 'asc' ? 'desc' : 'asc';
        else order = 'desc';
        url.searchParams.set('sort', sort);
        url.searchParams.set('order', order);
        location.href = url.toString();
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
        if (!confirm('Отправить сообщение в группу и гостю повторно?')) return;
        const id = String(btn.dataset.id || '');
        if (!id) return;
        const statusEl = q(`#resend-status-${id}`);
        btn.disabled = true;
        if (statusEl) {
          statusEl.textContent = 'Отправка…';
          statusEl.style.color = 'var(--muted)';
        }
        try {
          const { res, j } = await postForm('reservations.php?ajax=resend', { id });
          if (res.ok && j && j.ok) {
            const parts = [];
            parts.push(j.group_ok ? 'Группа: ОК' : 'Группа: Ошибка');
            if (j.has_tg) parts.push(j.guest_ok ? 'Гость: ОК' : 'Гость: Ошибка');
            else parts.push('Гость: нет TG');
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
    if (!cb) return;
    cb.addEventListener('change', () => {
      const url = new URL(location.href);
      if (cb.checked) url.searchParams.set('show_deleted', '1');
      else url.searchParams.delete('show_deleted');
      location.href = url.toString();
    });
  };

  bindSort();
  bindResend();
  bindDelete();
  bindShowDeleted();
})();
