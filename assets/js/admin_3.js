(() => {
            const fire = () => window.dispatchEvent(new Event('resize'));
            const kick = () => {
                requestAnimationFrame(() => {
                    fire();
                    requestAnimationFrame(fire);
                });
                setTimeout(fire, 200);
                setTimeout(fire, 800);
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', kick, { once: true });
            } else {
                kick();
            }
            window.addEventListener('load', () => {
                fire();
                setTimeout(fire, 300);
            });
        })();

        (() => {
            const bar = document.createElement('div');
            bar.className = 'sticky-hscroll-bar';
            bar.style.display = 'none';
            bar.innerHTML = '<div class="sticky-hscroll"><div class="sticky-hscroll-viewport"><div class="sticky-hscroll-content"></div></div></div>';
            document.body.appendChild(bar);

            const viewport = bar.querySelector('.sticky-hscroll-viewport');
            const content = bar.querySelector('.sticky-hscroll-content');

            let target = null;
            let syncingFromViewport = false;
            let syncingFromTarget = false;
            let ro = null;
            let raf = 0;

            const pickTarget = () => {
                const wraps = Array.from(document.querySelectorAll('.table-wrap'));
                let best = null;
                let bestW = 0;
                for (const w of wraps) {
                    const sw = w.scrollWidth || 0;
                    if (sw > bestW) {
                        bestW = sw;
                        best = w;
                    }
                }
                return best;
            };

            const update = () => {
                if (!target) {
                    bar.style.display = 'none';
                    document.body.style.paddingBottom = '';
                    return;
                }
                const needs = (target.scrollWidth - target.clientWidth) > 2;
                bar.style.display = needs ? '' : 'none';
                document.body.style.paddingBottom = needs ? '46px' : '';
                if (!needs) return;
                content.style.width = target.scrollWidth + 'px';
                viewport.scrollLeft = target.scrollLeft;
            };

            const scheduleUpdate = () => {
                if (raf) cancelAnimationFrame(raf);
                raf = requestAnimationFrame(update);
            };

            const attach = () => {
                const next = pickTarget();
                if (next === target) {
                    scheduleUpdate();
                    return;
                }
                if (target) {
                    target.removeEventListener('scroll', onTargetScroll);
                }
                if (ro) {
                    ro.disconnect();
                    ro = null;
                }
                target = next;
                if (!target) {
                    update();
                    return;
                }
                target.addEventListener('scroll', onTargetScroll, { passive: true });
                if (window.ResizeObserver) {
                    ro = new ResizeObserver(() => scheduleUpdate());
                    ro.observe(target);
                    const table = target.querySelector('table');
                    if (table) ro.observe(table);
                }
                scheduleUpdate();
            };

            const onTargetScroll = () => {
                if (syncingFromViewport) return;
                syncingFromTarget = true;
                viewport.scrollLeft = target ? target.scrollLeft : 0;
                syncingFromTarget = false;
            };

            viewport.addEventListener('scroll', () => {
                if (!target) return;
                if (syncingFromTarget) return;
                syncingFromViewport = true;
                target.scrollLeft = viewport.scrollLeft;
                syncingFromViewport = false;
            }, { passive: true });

            window.addEventListener('resize', scheduleUpdate);

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', attach);
            } else {
                attach();
            }
            setTimeout(attach, 0);
        })();

        (() => {
            const els = Array.from(document.querySelectorAll('.js-local-dt'));
            if (els.length === 0) return;
            els.forEach((el) => {
                const iso = (el.getAttribute('data-iso') || '').trim();
                if (!iso) return;
                const d = new Date(iso);
                if (Number.isNaN(d.getTime())) return;
                el.textContent = d.toLocaleString();
            });
        })();

        (() => {
            const modal = document.getElementById('permModal');
            const form = document.getElementById('permForm');
            const emailEl = document.getElementById('permEmail');
            const tgEl = document.getElementById('permTgUsername');
            const cancel = document.getElementById('permCancel');
            if (!modal || !form || !emailEl || !tgEl || !cancel) return;

            const defaultPerms = {
                dashboard: false,
                rawdata: false,
                kitchen_online: false,
                employees: false,
                payday: false,
                admin: false,
                roma: false,
                banya: false,
                exclude_toggle: false,
            };

            const close = () => { modal.style.display = 'none'; };
            const open = (email, perms, tg) => {
                emailEl.value = email;
                tgEl.value = (tg || '').trim();
                const p = Object.assign({}, defaultPerms, perms || {});
                if (p.telegram_ack && !p.exclude_toggle) p.exclude_toggle = true;
                p.telegram_ack = !!p.exclude_toggle;
                Array.from(form.querySelectorAll('input[type="checkbox"][id^="perm_"]')).forEach((cb) => {
                    const k = String(cb.id).slice('perm_'.length);
                    cb.checked = !!p[k];
                });
                modal.style.display = 'flex';
            };

            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.perm-gear');
                if (!btn) return;
                const email = btn.getAttribute('data-email') || '';
                const tg = btn.getAttribute('data-tg') || '';
                let perms = null;
                try { perms = JSON.parse(btn.getAttribute('data-perms') || 'null'); } catch (_) { perms = null; }
                open(email, perms, tg);
            });
            modal.addEventListener('click', (e) => {
                if (e.target.classList.contains('perm-modal-backdrop')) close();
            });
            cancel.addEventListener('click', close);
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') close();
            });
        })();
