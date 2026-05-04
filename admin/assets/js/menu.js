(() => {
                        const toggles = Array.from(document.querySelectorAll('.col-toggle'));
                        const keys = toggles.map((cb) => cb.getAttribute('data-col')).filter(Boolean);
                        const params = new URLSearchParams(window.location.search);
                        const fromUrl = (params.get('cols') || '').trim();
                        const userEmail = (window.ADMIN && typeof window.ADMIN.userEmail === 'string') ? window.ADMIN.userEmail : '';
                        const storageKey = userEmail ? `menu_cols:${userEmail}` : 'menu_cols';
                        let fromSession = '';
                        let fromStorage = '';
                        try { fromSession = (sessionStorage.getItem(storageKey) || '').trim(); } catch (e) {}
                        try { fromStorage = (localStorage.getItem(storageKey) || '').trim(); } catch (e) {}
                        const initial = fromUrl || fromSession || fromStorage;
                        let selected = new Set();
                        if (initial) {
                            initial.split(',').map(s => s.trim()).filter(Boolean).forEach(k => selected.add(k));
                        } else {
                            toggles.forEach((cb) => {
                                const k = cb.getAttribute('data-col');
                                const isDefault = cb.getAttribute('data-default') === '1';
                                if (k && isDefault) {
                                    selected.add(k);
                                }
                            });
                        }

                        const apply = () => {
                            keys.forEach((k) => {
                                const show = selected.has(k);
                                document.querySelectorAll(`[data-col="${k}"]`).forEach((el) => {
                                    el.style.display = show ? '' : 'none';
                                });
                            });
                            document.querySelectorAll('.col-toggle').forEach((cb) => {
                                const k = cb.getAttribute('data-col');
                                cb.checked = selected.has(k);
                            });
                            params.set('cols', Array.from(selected).join(','));
                            const newUrl = `${window.location.pathname}?${params.toString()}`;
                            window.history.replaceState({}, '', newUrl);
                            const value = Array.from(selected).join(',');
                            try { sessionStorage.setItem(storageKey, value); } catch (e) {}
                            try { localStorage.setItem(storageKey, value); } catch (e) {}
                            const hidden = document.querySelector('input[name="cols"]');
                            if (hidden) hidden.value = Array.from(selected).join(',');
                        };

                        document.querySelectorAll('.col-toggle').forEach((cb) => {
                            cb.addEventListener('change', () => {
                                const k = cb.getAttribute('data-col');
                                if (cb.checked) selected.add(k);
                                else selected.delete(k);
                                apply();
                            });
                        });
                        apply();
                    })();

                    document.querySelectorAll('.publish-toggle').forEach((el) => {
                        el.addEventListener('change', async () => {
                            const posterId = parseInt(el.getAttribute('data-poster-id'), 10);
                            const isHidden = el.checked;
                            const isPublished = !isHidden;
                            el.disabled = true;
                            try {
                                const res = await fetch('/admin/?ajax=menu_publish', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ poster_id: posterId, is_published: isPublished })
                                });
                                const data = await res.json().catch(() => ({}));
                                if (!res.ok || !data.ok) {
                                    el.checked = !isPublished;
                                    alert((data && data.error) ? data.error : 'Ошибка обновления');
                                }
                                if (data && data.disabled) {
                                    el.disabled = true;
                                } else {
                                    el.disabled = false;
                                }
                            } catch (e) {
                                el.checked = !isPublished;
                                el.disabled = false;
                                alert('Ошибка сети');
                            }
                        });
                    });

                    const qs = (sel, root = document) => root.querySelector(sel);
                    const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

                    const formatVnd = (value) => {
                        const s = String(value ?? '').trim();
                        if (!s) return '';
                        const n = Number(String(s).replace(/\s+/g, '').replace(',', '.'));
                        if (!Number.isFinite(n)) return s;
                        const i = Math.round(n);
                        return i.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                    };

                    const renderStatus = (td) => {
                        if (!td) return;
                        const isActive = String(td.getAttribute('data-status-active') || '0') === '1';
                        const isPublished = String(td.getAttribute('data-status-published') || '0') === '1';
                        const isUnadapted = String(td.getAttribute('data-status-unadapted') || '0') === '1';
                        const parts = [];
                        parts.push(isActive ? '<span class="status-ind status-ok">Poster</span>' : '<span class="status-ind status-bad">Не найдено</span>');
                        parts.push((isPublished && isActive) ? '<span class="status-ind status-ok">Опублик.</span>' : '<span class="status-ind status-warn">Скрыто</span>');
                        if (isUnadapted) parts.push('<span class="status-ind status-warn">!</span>');
                        td.innerHTML = parts.join(' ');
                    };

                    const autoWidthSelects = () => {
                        const selects = qsa('select[data-autowidth="1"]');
                        if (selects.length === 0) return;
                        const meas = document.createElement('span');
                        meas.style.position = 'absolute';
                        meas.style.visibility = 'hidden';
                        meas.style.whiteSpace = 'pre';
                        meas.style.left = '-9999px';
                        meas.style.top = '-9999px';
                        document.body.appendChild(meas);

                        for (const sel of selects) {
                            const cs = window.getComputedStyle(sel);
                            meas.style.font = cs.font;
                            let maxW = 0;
                            for (const opt of Array.from(sel.options || [])) {
                                meas.textContent = String(opt.textContent || '').trim();
                                const w = meas.getBoundingClientRect().width;
                                if (w > maxW) maxW = w;
                            }
                            const pad = 48;
                            const target = Math.ceil(maxW + pad);
                            sel.style.width = `${target}px`;
                            sel.style.maxWidth = '100%';
                        }

                        meas.remove();
                    };
                    autoWidthSelects();
                    window.addEventListener('resize', () => {
                        window.clearTimeout(window.__adminMenuSelectT);
                        window.__adminMenuSelectT = window.setTimeout(autoWidthSelects, 150);
                    });

                    const table = qs('table.menu-table');
                    const tbody = table ? qs('tbody', table) : null;

                    const getCellSortValue = (tr, key) => {
                        if (!tr) return '';
                        if (key === 'poster_id') {
                            const v = Number(tr.getAttribute('data-poster-id') || 0);
                            return Number.isFinite(v) ? v : 0;
                        }
                        if (key === 'price') {
                            const td = qs('td[data-col="price"]', tr);
                            const raw = td ? String(td.textContent || '').trim() : '';
                            const n = Number(raw.replace(/\s+/g, '').replace(',', '.'));
                            return Number.isFinite(n) ? n : 0;
                        }
                        if (key === 'status') {
                            const td = qs('td[data-col="status"]', tr);
                            if (!td) return 0;
                            const isActive = String(td.getAttribute('data-status-active') || '0') === '1';
                            const isPublished = String(td.getAttribute('data-status-published') || '0') === '1';
                            const isUnadapted = String(td.getAttribute('data-status-unadapted') || '0') === '1';
                            let rank = isActive ? (isPublished ? 1 : 2) : 3;
                            if (isUnadapted) rank += 0.1;
                            return rank;
                        }
                        if (key === 'title_ru') {
                            const td = qs('td[data-col="title_ru"]', tr);
                            const main = td ? qs('div', td) : null;
                            const txt = main ? String(main.textContent || '').trim() : '';
                            return txt.toLowerCase();
                        }
                        const td = qs(`td[data-col="${key}"]`, tr);
                        const txt = td ? String(td.textContent || '').trim() : '';
                        return txt.toLowerCase();
                    };

                    const sortRows = (key, dir) => {
                        if (!tbody) return;
                        const rows = qsa('tr', tbody);
                        const factor = dir === 'desc' ? -1 : 1;
                        const withIdx = rows.map((tr, idx) => ({ tr, idx, v: getCellSortValue(tr, key) }));
                        withIdx.sort((a, b) => {
                            if (a.v === b.v) return a.idx - b.idx;
                            if (typeof a.v === 'number' && typeof b.v === 'number') return (a.v - b.v) * factor;
                            return String(a.v).localeCompare(String(b.v), 'ru', { sensitivity: 'base' }) * factor;
                        });
                        for (const it of withIdx) tbody.appendChild(it.tr);
                    };

                    const setSortUi = (key, dir) => {
                        qsa('.js-sort .sort-arrow').forEach((s) => { s.textContent = ''; });
                        const a = qs(`.js-sort[data-sort-key="${key}"]`);
                        const arrow = a ? qs('.sort-arrow', a) : null;
                        if (arrow) arrow.textContent = dir === 'desc' ? '▼' : '▲';
                    };

                    qsa('.js-sort').forEach((a) => {
                        a.addEventListener('click', (e) => {
                            e.preventDefault();
                            if (!table) return;
                            const key = String(a.getAttribute('data-sort-key') || '');
                            if (!key) return;
                            const curKey = String(table.getAttribute('data-sort-key') || '');
                            const curDir = String(table.getAttribute('data-sort-dir') || 'asc');
                            const nextDir = (curKey === key && curDir === 'asc') ? 'desc' : 'asc';
                            table.setAttribute('data-sort-key', key);
                            table.setAttribute('data-sort-dir', nextDir);
                            sortRows(key, nextDir);
                            setSortUi(key, nextDir);
                        });
                    });

                    const ensureModal = () => {
                        let modal = qs('#adminMenuModal');
                        if (modal) return modal;
                        modal = document.createElement('div');
                        modal.id = 'adminMenuModal';
                        modal.className = 'admin-modal';
                        modal.innerHTML = '<div class="admin-modal-backdrop" data-close="1"></div><div class="admin-modal-card"><div class="admin-modal-head"><div class="admin-modal-title">Редактирование</div><button type="button" class="admin-modal-close" data-close="1">×</button></div><div class="admin-modal-body"></div></div>';
                        document.body.appendChild(modal);
                        modal.addEventListener('click', (e) => {
                            const t = e.target;
                            if (t && t.getAttribute && t.getAttribute('data-close') === '1') {
                                modal.classList.remove('is-open');
                            }
                        });
                        document.addEventListener('keydown', (e) => {
                            if (e.key === 'Escape') modal.classList.remove('is-open');
                        });
                        return modal;
                    };

                    const openEditModal = async (posterId) => {
                        const modal = ensureModal();
                        const body = qs('.admin-modal-body', modal);
                        if (!body) return;
                        modal.classList.add('is-open');
                        body.innerHTML = '<div class="muted">Загрузка…</div>';
                        try {
                            const res = await fetch(`/admin/?ajax=menu_edit_form&poster_id=${encodeURIComponent(String(posterId))}`, { method: 'GET' });
                            const html = await res.text();
                            if (!res.ok) {
                                body.innerHTML = `<div class="error">${html || 'Ошибка загрузки'}</div>`;
                                return;
                            }
                            body.innerHTML = html;
                            const form = qs('form', body);
                            if (form) {
                                form.addEventListener('submit', async (e) => {
                                    e.preventDefault();
                                    const fd = new FormData(form);
                                    fd.set('save_menu_item', '1');
                                    try {
                                        const saveRes = await fetch('/admin/?ajax=menu_edit_save', { method: 'POST', body: fd });
                                        const data = await saveRes.json().catch(() => null);
                                        if (!saveRes.ok || !data || !data.ok) {
                                            const msg = data && data.error ? data.error : 'Ошибка сохранения';
                                            alert(msg);
                                            return;
                                        }
                                        const r = data.row || {};
                                        const tr = qs(`tr[data-poster-id="${posterId}"]`);
                                        if (tr) {
                                            const tdRu = qs('td[data-col="title_ru"]', tr);
                                            const tdEn = qs('td[data-col="title_en"]', tr);
                                            const tdVn = qs('td[data-col="title_vn"]', tr);
                                            const tdKo = qs('td[data-col="title_ko"]', tr);
                                            const tdPrice = qs('td[data-col="price"]', tr);
                                            const tdStatus = qs('td[data-col="status"]', tr);
                                            const main = tdRu ? qs('div', tdRu) : null;
                                            if (main) main.textContent = (String(r.ru_title || '').trim() || String(r.name_raw || '').trim());
                                            if (tdEn) tdEn.textContent = String(r.en_title || '').trim();
                                            if (tdVn) tdVn.textContent = String(r.vn_title || '').trim();
                                            if (tdKo) tdKo.textContent = String(r.ko_title || '').trim();
                                            if (tdPrice) tdPrice.textContent = formatVnd(r.price_raw);
                                            const isActive = Number(r.is_active || 0) === 1;
                                            const isPublished = Number(r.is_published || 0) === 1;
                                            const isUnadapted = (String(r.ru_title || '').trim() === '' || String(r.en_title || '').trim() === '' || String(r.vn_title || '').trim() === '');
                                            if (tdStatus) {
                                                tdStatus.setAttribute('data-status-active', isActive ? '1' : '0');
                                                tdStatus.setAttribute('data-status-published', isPublished ? '1' : '0');
                                                tdStatus.setAttribute('data-status-unadapted', isUnadapted ? '1' : '0');
                                                renderStatus(tdStatus);
                                            }
                                            const toggle = qs('.publish-toggle', tr);
                                            if (toggle) {
                                                toggle.checked = !isPublished || !isActive;
                                                toggle.disabled = !isActive;
                                            }
                                        }
                                        modal.classList.remove('is-open');
                                    } catch (_e) {
                                        alert('Ошибка сети');
                                    }
                                });
                            }
                        } catch (_e) {
                            body.innerHTML = '<div class="error">Ошибка сети</div>';
                        }
                    };

                    document.addEventListener('click', (e) => {
                        const t = e.target;
                        const a = t && t.closest ? t.closest('.js-edit-btn') : null;
                        if (!a) return;
                        const posterId = parseInt(String(a.getAttribute('data-poster-id') || '0'), 10);
                        if (!posterId) return;
                        e.preventDefault();
                        openEditModal(posterId);
                    });

                    document.querySelectorAll('.publish-toggle').forEach((el) => {
                        const posterId = parseInt(el.getAttribute('data-poster-id'), 10);
                        const tr = qs(`tr[data-poster-id="${posterId}"]`);
                        const st = tr ? qs('td[data-col="status"]', tr) : null;
                        if (st) renderStatus(st);
                    });

})();
