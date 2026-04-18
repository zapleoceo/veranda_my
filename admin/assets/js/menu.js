(() => {
                        const toggles = Array.from(document.querySelectorAll('.col-toggle'));
                        const keys = toggles.map((cb) => cb.getAttribute('data-col')).filter(Boolean);
                        const params = new URLSearchParams(window.location.search);
                        const fromUrl = (params.get('cols') || '').trim();
                        const userEmail = <?= json_encode((string)($_SESSION['user_email'] ?? ''), JSON_UNESCAPED_UNICODE) ?>;
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
                            const prev = !el.checked;
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