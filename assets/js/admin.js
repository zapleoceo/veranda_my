(() => {
                    const root = document.currentScript && document.currentScript.parentElement ? document.currentScript.parentElement : document;
                    const allBtn = document.querySelector('[data-select-all]');
                    const noneBtn = document.querySelector('[data-select-none]');
                    const boxes = () => Array.from(document.querySelectorAll('input[type="checkbox"][name="allowed_nums[]"]'));
                    if (allBtn) allBtn.addEventListener('click', () => boxes().forEach((cb) => { cb.checked = true; }));
                    if (noneBtn) noneBtn.addEventListener('click', () => boxes().forEach((cb) => { cb.checked = false; }));

                    const table = document.getElementById('resTablesTable');
                    const sortTh = document.getElementById('resTablesSortTitle');
                    const sortArrow = document.getElementById('resTablesSortArrow');
                    const hideEmpty = document.getElementById('hideEmptyCaps');
                    if (table) {
                        const getRows = () => Array.from(table.tBodies[0] ? table.tBodies[0].rows : []);
                        const getCapValue = (tr) => {
                            const input = tr.querySelector('input.cap-input');
                            if (!input) return null;
                            const v = String(input.value || '').trim();
                            if (v === '') return 0;
                            const n = Number(v);
                            return Number.isFinite(n) ? n : 0;
                        };
                        const applyHide = () => {
                            const on = !!(hideEmpty && hideEmpty.checked);
                            getRows().forEach((tr) => {
                                const cap = getCapValue(tr);
                                tr.style.display = (on && (!cap || cap <= 0)) ? 'none' : '';
                            });
                        };
                        if (hideEmpty) hideEmpty.addEventListener('change', applyHide);
                        table.addEventListener('input', (e) => {
                            const t = e.target;
                            if (t && t.classList && t.classList.contains('cap-input')) applyHide();
                        });
                        applyHide();

                        const sortState = { dir: 'asc' };
                        const cellText = (tr, idx) => {
                            const td = tr.cells && tr.cells[idx] ? tr.cells[idx] : null;
                            return td ? String(td.textContent || '').trim().toLowerCase() : '';
                        };
                        const applySortIcon = () => {
                            if (!sortArrow) return;
                            sortArrow.textContent = sortState.dir === 'asc' ? '▲' : '▼';
                        };
                        const sortByTitle = () => {
                            if (!sortTh || !table.tBodies[0]) return;
                            const idx = Number.isFinite(sortTh.cellIndex) ? sortTh.cellIndex : Array.from(sortTh.parentElement ? sortTh.parentElement.children : []).indexOf(sortTh);
                            if (idx < 0) return;
                            const rows = getRows();
                            const dir = sortState.dir === 'asc' ? 1 : -1;
                            rows.sort((a, b) => {
                                const av = cellText(a, idx);
                                const bv = cellText(b, idx);
                                if (av < bv) return -1 * dir;
                                if (av > bv) return 1 * dir;
                                return 0;
                            });
                            rows.forEach((r) => table.tBodies[0].appendChild(r));
                            applyHide();
                            applySortIcon();
                        };
                        if (sortTh) {
                            applySortIcon();
                            sortTh.addEventListener('click', () => {
                                sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
                                sortByTitle();
                            });
                        }
                    }
                })();
