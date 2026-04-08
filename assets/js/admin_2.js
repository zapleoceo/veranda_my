(() => {
                        const sel = document.getElementById('script_name');
                        const out = document.getElementById('script_desc');
                        const upd = () => {
                            if (!sel || !out) return;
                            const opt = sel.options[sel.selectedIndex];
                            out.textContent = opt && opt.dataset && opt.dataset.desc ? opt.dataset.desc : '';
                        };
                        if (sel) sel.addEventListener('change', upd);
                        upd();
                    })();
