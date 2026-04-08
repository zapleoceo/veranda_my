document.addEventListener('DOMContentLoaded', () => {
            const header = document.getElementById('mainTableHeader');
            const list = document.getElementById('receiptsList');
            const statusEl = document.getElementById('lazyStatus');
            const sentinel = document.getElementById('lazySentinel');
            
            let currentSort = { field: 'receipt', order: 'asc' };
            let userSorted = false;
            const state = { offset: 0, limit: 20, loading: false, done: false, total: null };

            const baseParams = new URLSearchParams(window.location.search);
            baseParams.delete('ajax');
            baseParams.delete('offset');
            baseParams.delete('limit');

            const applySort = () => {
                const items = Array.from(list.getElementsByClassName('receipt-item'));
                if (items.length === 0) return;

                const { field, order } = currentSort;
                const sortedItems = items.sort((a, b) => {
                    let valA, valB;
                    switch (field) {
                        case 'receipt':
                            valA = a.dataset.receipt || '';
                            valB = b.dataset.receipt || '';
                            return order === 'asc'
                                ? valA.localeCompare(valB, undefined, { numeric: true })
                                : valB.localeCompare(valA, undefined, { numeric: true });
                        case 'opened':
                            valA = parseInt(a.dataset.opened || '0', 10);
                            valB = parseInt(b.dataset.opened || '0', 10);
                            break;
                        case 'closed':
                            valA = parseInt(a.dataset.closed || '0', 10);
                            valB = parseInt(b.dataset.closed || '0', 10);
                            break;
                        case 'wait':
                            valA = parseFloat(a.dataset.wait || '0');
                            valB = parseFloat(b.dataset.wait || '0');
                            break;
                    }
                    return order === 'asc' ? (valA - valB) : (valB - valA);
                });

                sortedItems.forEach(item => list.appendChild(item));
            };

            const updateLiveCooking = () => {
                const els = Array.from(list.getElementsByClassName('live-wait'));
                if (els.length === 0) return;
                const nowSec = Math.floor(Date.now() / 1000);
                for (const el of els) {
                    const sentTs = parseInt(el.dataset.sentTs || '0', 10);
                    if (!sentTs) continue;
                    const diffSec = Math.max(0, nowSec - sentTs);
                    const mm = Math.floor(diffSec / 60);
                    const ss = diffSec % 60;
                    const out = String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
                    const t = el.querySelector('.live-time');
                    if (t) t.textContent = out;
                }
            };

            const updateStatus = () => {
                if (!statusEl) return;
                if (state.loading) {
                    statusEl.textContent = 'Загрузка…';
                    statusEl.style.height = '18px';
                    statusEl.style.margin = '16px 0';
                    return;
                }
                if (state.total !== null && list.children.length === 0 && state.done) {
                    statusEl.textContent = 'Нет данных';
                    statusEl.style.height = '18px';
                    statusEl.style.margin = '16px 0';
                    return;
                }
                statusEl.textContent = '';
                statusEl.style.height = '0';
                statusEl.style.margin = '0';
            };

            const loadNext = async () => {
                if (state.loading || state.done) return;
                state.loading = true;
                updateStatus();
                try {
                    const params = new URLSearchParams(baseParams);
                    params.set('ajax', '1');
                    params.set('offset', String(state.offset));
                    params.set('limit', String(state.limit));

                    const res = await fetch(`rawdata.php?${params.toString()}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) throw new Error('load failed');
                    const data = await res.json();
                    if (!data || !data.ok) throw new Error('bad response');

                    state.total = typeof data.total_receipts === 'number' ? data.total_receipts : state.total;
                    if (data.html) {
                        const tmp = document.createElement('div');
                        tmp.innerHTML = data.html;
                        while (tmp.firstChild) list.appendChild(tmp.firstChild);
                        if (userSorted) applySort();
                        updateLiveCooking();
                    }
                    state.offset = typeof data.next_offset === 'number' ? data.next_offset : (state.offset + state.limit);
                    state.done = !data.has_more;
                } catch (e) {
                    state.done = true;
                    if (statusEl) {
                        statusEl.textContent = 'Ошибка загрузки';
                        statusEl.style.display = 'block';
                    }
                } finally {
                    state.loading = false;
                    updateStatus();
                }
            };

            header.addEventListener('click', (e) => {
                const target = e.target.closest('div[data-sort]');
                if (!target) return;

                const field = target.dataset.sort;
                const order = (currentSort.field === field && currentSort.order === 'asc') ? 'desc' : 'asc';

                // Update UI
                header.querySelectorAll('div').forEach(div => {
                    div.classList.remove('sort-asc', 'sort-desc');
                });
                target.classList.add(`sort-${order}`);

                currentSort = { field, order };
                userSorted = true;
                applySort();
            });

            if (sentinel) {
                const io = new IntersectionObserver((entries) => {
                    if (entries.some(e => e.isIntersecting)) loadNext();
                }, { rootMargin: '600px 0px 600px 0px' });
                io.observe(sentinel);
            }
            loadNext();
            setInterval(updateLiveCooking, 1000);

            const resync = document.querySelector('input[name="resync"][type="checkbox"]');
            if (resync) {
                resync.checked = false;
                resync.addEventListener('change', () => {
                    if (resync.checked) {
                        const ok = confirm('Resync делает полную пересинхронизацию данных из Poster за выбранный период и может сильно нагрузить систему. Используй редко. Продолжить?');
                        if (!ok) resync.checked = false;
                    }
                });
            }
            const form = document.getElementById('rawdataFilters');
            if (form && resync) {
                form.addEventListener('submit', (e) => {
                    if (resync.checked) {
                        const ok = confirm('Подтвердить Resync? Это может занять время и нагрузить систему.');
                        if (!ok) e.preventDefault();
                    }
                });
            }

            list.addEventListener('change', async (e) => {
                const checkbox = e.target.closest('input[name="exclude_from_dashboard"]');
                if (!checkbox) return;
                const form = checkbox.closest('form.exclude-item-form');
                if (!form) return;

                const payload = new FormData(form);
                if (!checkbox.checked) {
                    payload.delete('exclude_from_dashboard');
                }
                const indicator = form.querySelector('.save-indicator');

                checkbox.disabled = true;
                try {
                    const response = await fetch('rawdata.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: payload
                    });
                    if (!response.ok) {
                        checkbox.checked = !checkbox.checked;
                    } else if (indicator) {
                        indicator.classList.add('show');
                        indicator.textContent = 'сохранено';
                        setTimeout(() => indicator.classList.remove('show'), 1200);
                    }
                } catch (err) {
                    checkbox.checked = !checkbox.checked;
                    if (indicator) {
                        indicator.classList.add('show');
                        indicator.textContent = 'ошибка';
                        setTimeout(() => {
                            indicator.textContent = 'сохранено';
                            indicator.classList.remove('show');
                        }, 1500);
                    }
                } finally {
                    checkbox.disabled = false;
                }
            });
        });
