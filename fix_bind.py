with open('/workspace/payday/index.php', 'r') as f:
    content = f.read()

# Let's remove bindInTableEvents completely, and just do the binding.
# Actually, I can redefine bindInTableEvents to only include what's needed.

old_block = """    const bindInTableEvents = () => {
        document.querySelectorAll('input.sepay-cb').forEach((cb) => {
            cb.addEventListener('change', () => {
                const id = Number(cb.getAttribute('data-id') || 0);
                if (!id) return;
                if (cb.checked) selectedSepay.add(id);
                else selectedSepay.delete(id);
                updateLinkButtonState();
            });
        });
        document.querySelectorAll('input.poster-cb').forEach((cb) => {
            cb.addEventListener('change', () => {
                const id = Number(cb.getAttribute('data-id') || 0);
                if (!id) return;
                if (cb.checked) selectedPoster.add(id);
                else selectedPoster.delete(id);
                updateLinkButtonState();
            });
        });
        document.querySelectorAll('button.sepay-hide').forEach((btn) => {
            if (btn.classList.contains('out-hide')) return;
            btn.addEventListener('click', () => {
                const sepayId = Number(btn.getAttribute('data-sepay-id') || 0);
                if (!sepayId) return;
                const comment = prompt('Комментарий (почему скрываем этот платеж):', '');
                if (comment === null) return;
                const c = String(comment || '').trim();
                if (!c) return;
                fetch('?ajax=sepay_hide', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sepay_id: sepayId, comment: c }),
                })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                    const tr = btn.closest('tr');
                    if (tr) {
                        tr.classList.add('row-hidden');
                        tr.setAttribute('data-hidden', '1');
                        tr.setAttribute('data-content', String(c).toLowerCase());
                        const td = tr.querySelector('td.col-sepay-content');
                        if (td) td.textContent = c;
                    }
                    selectedSepay.delete(sepayId);
                    updateStats();
                    applyHideLinked();
                    drawLines();
                    try { scheduleRelayoutBurst(); } catch (e) {}
                })
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
            });
        });"""

new_block = """    const bindInTableEvents = () => {
        document.querySelectorAll('#sepayTable tbody input.sepay-cb').forEach((cb) => {
            cb.addEventListener('change', () => {
                const id = Number(cb.getAttribute('data-id') || 0);
                if (!id) return;
                if (cb.checked) selectedSepay.add(id);
                else selectedSepay.delete(id);
                updateLinkButtonState();
            });
        });
        document.querySelectorAll('#posterTable tbody input.poster-cb').forEach((cb) => {
            cb.addEventListener('change', () => {
                const id = Number(cb.getAttribute('data-id') || 0);
                if (!id) return;
                if (cb.checked) selectedPoster.add(id);
                else selectedPoster.delete(id);
                updateLinkButtonState();
            });
        });
        document.querySelectorAll('#sepayTable tbody button.sepay-hide').forEach((btn) => {
            if (btn.classList.contains('out-hide')) return;
            btn.addEventListener('click', () => {
                const sepayId = Number(btn.getAttribute('data-sepay-id') || 0);
                if (!sepayId) return;
                const comment = prompt('Комментарий (почему скрываем этот платеж):', '');
                if (comment === null) return;
                const c = String(comment || '').trim();
                if (!c) return;
                fetch('?ajax=sepay_hide', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sepay_id: sepayId, comment: c }),
                })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                    const tr = btn.closest('tr');
                    if (tr) {
                        tr.classList.add('row-hidden');
                        tr.setAttribute('data-hidden', '1');
                        tr.setAttribute('data-content', String(c).toLowerCase());
                        const td = tr.querySelector('td.col-sepay-content');
                        if (td) td.textContent = c;
                    }
                    selectedSepay.delete(sepayId);
                    updateStats();
                    applyHideLinked();
                    drawLines();
                    try { scheduleRelayoutBurst(); } catch (e) {}
                })
                .catch((e) => alert(e && e.message ? e.message : 'Ошибка'));
            });
        });
    };"""

content = content.replace(old_block, new_block)

# Remove the form.finance-transfer and setupSort from bindInTableEvents
to_remove = """        document.querySelectorAll('form.finance-transfer').forEach((form) => {
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const btn = form.querySelector('button.btn');
                const statusEl = form.querySelector('.finance-status');
                if (btn && btn.disabled) return;
                const kind = String(form.getAttribute('data-kind') || '');
                const dateFrom = String(form.getAttribute('data-date-from') || '');
                const dateTo = String(form.getAttribute('data-date-to') || '');
                if (!kind || !dateFrom || !dateTo) return;
                const creatorEmail = String(window.__USER_EMAIL__ || '').trim();
                const commentBase = (kind === 'vietnam') ? 'Перевод чеков вьетнаской компании' : 'Перевод типсов';
                const comment = creatorEmail ? (commentBase + ' by ' + creatorEmail) : commentBase;
                const accFromName = String(form.getAttribute('data-account-from-name') || '#1');
                const accToName = String(form.getAttribute('data-account-to-name') || (kind === 'vietnam' ? '#9' : '#8'));
                const sumVnd = Number(form.getAttribute('data-sum-vnd') || 0);
                const sumTxt = sumVnd ? (Number(sumVnd).toLocaleString('en-US') + ' ₫') : '—';

                const openConfirm = () => new Promise((resolve) => {
                    const backdrop = document.getElementById('financeConfirm');
                    const text = document.getElementById('financeConfirmText');
                    const cb = document.getElementById('financeConfirmChecked');
                    const ok = document.getElementById('financeConfirmOk');
                    const cancel = document.getElementById('financeConfirmCancel');
                    if (!backdrop || !text || !cb || !ok || !cancel) return resolve(false);
                    text.innerHTML =
                        `Будет создан перевод в Poster.<br>` +
                        `Счет списания: <b>${escapeHtml(accFromName)}</b><br>` +
                        `Счет зачисления: <b>${escapeHtml(accToName)}</b><br>` +
                        `Сумма: <b>${escapeHtml(sumTxt)}</b><br>` +
                        `Комментарий: <b>${escapeHtml(comment)}</b><br>` +
                        `Создатель: <b>${escapeHtml(creatorEmail || '—')}</b>`;
                    cb.checked = false;
                    ok.disabled = true;
                    backdrop.style.display = 'flex';

                    const close = (v) => {
                        backdrop.style.display = 'none';
                        cancel.removeEventListener('click', onCancel);
                        ok.removeEventListener('click', onOk);
                        cb.removeEventListener('change', onCb);
                        backdrop.removeEventListener('click', onBg);
                        document.removeEventListener('keydown', onEsc, true);
                        resolve(v);
                    };

                    const onCancel = () => close(false);
                    const onOk = () => close(true);
                    const onCb = () => { ok.disabled = !cb.checked; };
                    const onBg = (e2) => { if (e2.target === backdrop) close(false); };
                    const onEsc = (e2) => { if (e2.key === 'Escape') close(false); };

                    cancel.addEventListener('click', onCancel);
                    ok.addEventListener('click', onOk);
                    cb.addEventListener('change', onCb);
                    backdrop.addEventListener('click', onBg);
                    document.addEventListener('keydown', onEsc, true);
                });

                openConfirm().then((confirmed) => {
                    if (!confirmed) return;
                    btn.disabled = true;
                    if (statusEl) statusEl.innerHTML = '';
                    fetch('?ajax=finance_transfer', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ kind, dateFrom, dateTo, comment }),
                    })
                    .then((r) => r.json())
                    .then((j) => {
                        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                        if (statusEl) statusEl.innerHTML = `<span style="color:var(--green)">✓ Успех</span>`;
                    })
                    .catch((err) => {
                        if (statusEl) statusEl.innerHTML = `<span style="color:var(--red)">✗ Ошибка: ${escapeHtml(err && err.message ? err.message : String(err))}</span>`;
                    })
                    .finally(() => {
                        btn.disabled = false;
                    });
                });
            });
        });
        setupSort(sepayTable);
        setupSort(posterTable);
"""
# Replace to_remove with empty string but preserve the code outside of bindInTableEvents
replacement = to_remove + "\n    };\n    bindInTableEvents();"

new_placement = to_remove + "\n    bindInTableEvents();"

content = content.replace(replacement, new_placement)

with open('/workspace/payday/index.php', 'w') as f:
    f.write(content)
