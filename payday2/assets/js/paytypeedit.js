(() => {
    const ce = (tag) => document.createElement(tag);
    const digitsOnly = (s) => String(s || '').replace(/\D+/g, '');
    const readInt = (el) => {
        if (!el) return 0;
        const d = digitsOnly(el.value);
        return d ? (Number(d) || 0) : 0;
    };
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    const parseJsonLoose = (text) => {
        try { return JSON.parse(String(text || '')); } catch (_) { return null; }
    };

    const toast = (msg) => {
        const text = String(msg || '').trim();
        if (!text) return;
        let el = document.getElementById('pd2PaytypeToast');
        if (!el) {
            el = ce('div');
            el.id = 'pd2PaytypeToast';
            el.style.position = 'fixed';
            el.style.left = '50%';
            el.style.bottom = '18px';
            el.style.transform = 'translateX(-50%)';
            el.style.zIndex = '100000';
            el.style.padding = '10px 12px';
            el.style.borderRadius = '10px';
            el.style.border = '1px solid var(--border, #d6d6d6)';
            el.style.background = 'var(--card, #fff)';
            el.style.color = 'var(--text, #111827)';
            el.style.fontWeight = '900';
            el.style.boxShadow = '0 10px 30px rgba(0,0,0,0.22)';
            el.style.opacity = '0';
            el.style.transition = 'opacity 0.15s ease';
            document.body.appendChild(el);
        }
        el.textContent = text;
        el.style.opacity = '1';
        if (el._t) clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.opacity = '0'; }, 1800);
    };

    const state = {
        modal: null,
        title: null,
        err: null,
        txIdEl: null,
        btnClose: null,
        btnCancel: null,
        btnSave: null,
        loadingEl: null,
        authActions: null,
        btnOpenPosterLogin: null,
        btnOpenSettings: null,
        currentTxId: 0,
        fields: {},
        opening: false,
        saving: false,
    };

    const ensureModal = () => {
        if (state.modal) return;
        const backdrop = ce('div');
        backdrop.id = 'paytypeEditModal';
        backdrop.className = 'confirm-backdrop pd2-modal-backdrop';
        backdrop.style.display = 'none';
        const modal = ce('div');
        modal.className = 'confirm-modal pd2-modal-content';
        modal.setAttribute('role', 'dialog');
        modal.style.maxWidth = '720px';
        modal.style.width = '96vw';

        modal.innerHTML = `
            <div class="pd2-modal-header">
                <h3 class="pd2-m-0" id="paytypeEditTitle">Редактирование оплаты</h3>
                <button type="button" class="pd2-modal-close" id="paytypeEditClose">✕</button>
            </div>
            <div class="body pd2-modal-body pd2-p-15">
                <div class="muted" id="paytypeEditTxId" style="margin-bottom:10px;"></div>
                <div id="paytypeEditErr" class="error pd2-d-none" style="margin-bottom:10px;"></div>
                <div id="paytypeEditAuthActions" class="pd2-d-none" style="display:flex; justify-content:flex-end; gap:10px; margin-bottom:10px; flex-wrap: wrap;">
                    <button type="button" class="btn2" id="paytypeEditOpenPosterLogin">Открыть Poster login</button>
                    <button type="button" class="btn2" id="paytypeEditOpenSettings">Открыть настройки</button>
                </div>
                <div id="paytypeEditLoading" class="pd2-d-none pd2-text-center muted" style="padding: 14px 0;">
                    <svg class="pd2-loader-spin pd2-v-align-mid" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                    </svg>
                    <span style="margin-left:8px;">Загрузка...</span>
                </div>
                <div class="pd2-settings-grid-4" style="margin-bottom: 10px;">
                    <label class="pd2-settings-label pd2-m-0"><span>payedSum</span>
                        <input type="text" class="btn pd2-w-100" id="pte_payedSum" autocomplete="off" readonly>
                    </label>
                    <label class="pd2-settings-label pd2-m-0"><span>payedCash</span>
                        <input type="text" class="btn pd2-w-100" id="pte_payedCash" autocomplete="off">
                    </label>
                    <label class="pd2-settings-label pd2-m-0"><span>payedCard</span>
                        <input type="text" class="btn pd2-w-100" id="pte_payedCard" autocomplete="off">
                    </label>
                    <label class="pd2-settings-label pd2-m-0"><span>payedCert</span>
                        <input type="text" class="btn pd2-w-100" id="pte_payedCert" autocomplete="off">
                    </label>
                    <label class="pd2-settings-label pd2-m-0"><span>payedEwallet</span>
                        <input type="text" class="btn pd2-w-100" id="pte_payedEwallet" autocomplete="off">
                    </label>
                    <label class="pd2-settings-label pd2-m-0"><span>payedBonus</span>
                        <input type="text" class="btn pd2-w-100" id="pte_payedBonus" autocomplete="off">
                    </label>
                    <label class="pd2-settings-label pd2-m-0"><span>payedThirdParty</span>
                        <input type="text" class="btn pd2-w-100" id="pte_payedThirdParty" autocomplete="off">
                    </label>
                    <label class="pd2-settings-label pd2-m-0"><span>usesPayedCert</span>
                        <input type="checkbox" id="pte_usesPayedCert" style="margin-top: 10px;">
                    </label>
                </div>
                <div class="actions pd2-justify-between pd2-align-center pd2-mt-6">
                    <button type="button" class="btn2" id="paytypeEditCancel">Отмена</button>
                    <button type="button" class="btn2 primary" id="paytypeEditSave">Сохранить</button>
                </div>
            </div>
        `;

        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);

        state.modal = backdrop;
        state.title = modal.querySelector('#paytypeEditTitle');
        state.err = modal.querySelector('#paytypeEditErr');
        state.txIdEl = modal.querySelector('#paytypeEditTxId');
        state.btnClose = modal.querySelector('#paytypeEditClose');
        state.btnCancel = modal.querySelector('#paytypeEditCancel');
        state.btnSave = modal.querySelector('#paytypeEditSave');
        state.loadingEl = modal.querySelector('#paytypeEditLoading');
        state.authActions = modal.querySelector('#paytypeEditAuthActions');
        state.btnOpenPosterLogin = modal.querySelector('#paytypeEditOpenPosterLogin');
        state.btnOpenSettings = modal.querySelector('#paytypeEditOpenSettings');
        state.fields = {
            payedSum: modal.querySelector('#pte_payedSum'),
            payedCash: modal.querySelector('#pte_payedCash'),
            payedCard: modal.querySelector('#pte_payedCard'),
            payedCert: modal.querySelector('#pte_payedCert'),
            payedEwallet: modal.querySelector('#pte_payedEwallet'),
            payedBonus: modal.querySelector('#pte_payedBonus'),
            payedThirdParty: modal.querySelector('#pte_payedThirdParty'),
            usesPayedCert: modal.querySelector('#pte_usesPayedCert'),
        };

        const close = () => {
            if (!state.modal) return;
            state.modal.style.display = 'none';
            state.currentTxId = 0;
            if (state.err) { state.err.textContent = ''; state.err.classList.add('pd2-d-none'); }
            if (state.authActions) state.authActions.classList.add('pd2-d-none');
        };

        if (state.btnClose) state.btnClose.addEventListener('click', close);
        if (state.btnCancel) state.btnCancel.addEventListener('click', close);
        state.modal.addEventListener('click', (e) => { if (e.target === state.modal) close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && state.modal && state.modal.style.display === 'flex') close(); });

        const getAccount = () => {
            try {
                const ls = window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.localSettings ? window.PAYDAY_CONFIG.localSettings : null;
                const acc = ls && ls.poster_admin && ls.poster_admin.account ? String(ls.poster_admin.account).trim() : '';
                return acc;
            } catch (_) {
                return '';
            }
        };
        const openPosterLogin = () => {
            const acc = getAccount();
            if (!acc) return;
            window.open('https://' + encodeURIComponent(acc) + '.joinposter.com/manage/login', '_blank', 'noopener,noreferrer');
        };
        const openSettings = () => {
            const btn = document.getElementById('payday2SettingsBtn');
            if (btn) btn.click();
            try {
                const cookieEl = document.getElementById('pd2sett_padm_cookie');
                if (cookieEl && typeof cookieEl.focus === 'function') cookieEl.focus();
            } catch (_) {}
        };
        if (state.btnOpenPosterLogin) state.btnOpenPosterLogin.addEventListener('click', openPosterLogin);
        if (state.btnOpenSettings) state.btnOpenSettings.addEventListener('click', openSettings);

        if (state.btnSave) {
            state.btnSave.addEventListener('click', () => {
                if (state.saving || !state.currentTxId) return;
                const txId = state.currentTxId;
                const parts = {
                    payedCash: readInt(state.fields.payedCash),
                    payedCard: readInt(state.fields.payedCard),
                    payedCert: readInt(state.fields.payedCert),
                    payedEwallet: readInt(state.fields.payedEwallet),
                    payedBonus: readInt(state.fields.payedBonus),
                    payedThirdParty: readInt(state.fields.payedThirdParty),
                };
                const sum = Object.values(parts).reduce((a, b) => a + (Number(b) || 0), 0);
                if (state.fields.payedSum) state.fields.payedSum.value = String(sum);
                const params = {
                    payedSum: sum,
                    ...parts,
                    usesPayedCert: state.fields.usesPayedCert && state.fields.usesPayedCert.checked ? 1 : 0,
                };
                state.saving = true;
                if (state.btnSave) state.btnSave.disabled = true;
                setLoading(true);
                sleep(350)
                    .then(() => fetch('?ajax=poster_admin_edit_check', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ transaction_id: txId, params }),
                })))
                    .then((r) => r.text())
                    .then((t) => {
                        const j = parseJsonLoose(t);
                        if (!j || !j.ok) {
                            const msg = (j && j.error) ? String(j.error) : ('Ошибка: ' + String(t || '').slice(0, 300));
                            throw new Error(msg);
                        }
                        toast('Сохранено');
                        close();
                        try { window.dispatchEvent(new CustomEvent('pd2_checks_reload')); } catch (_) {}
                    })
                    .catch((e) => {
                        const msg = e && e.message ? String(e.message) : 'Ошибка';
                        if (state.err) {
                            state.err.textContent = msg;
                            state.err.classList.remove('pd2-d-none');
                        }
                        const needAuth = /redirect http|session expired|\blogin\b|не настроены cookies|заполните настройки/i.test(msg);
                        if (needAuth && state.authActions) state.authActions.classList.remove('pd2-d-none');
                    })
                    .finally(() => {
                        state.saving = false;
                        setLoading(false);
                        if (state.btnSave) state.btnSave.disabled = false;
                    });
            });
        }
    };

    const setLoading = (on) => {
        if (state.loadingEl) state.loadingEl.classList.toggle('pd2-d-none', !on);
        if (state.btnSave) state.btnSave.disabled = on;
    };

    const open = (txId) => {
        ensureModal();
        if (!state.modal || state.opening) return;
        state.opening = true;
        state.currentTxId = Number(txId || 0) || 0;
        if (state.modal) state.modal.style.display = 'flex';
        if (state.txIdEl) state.txIdEl.textContent = 'tx_id: ' + String(state.currentTxId);
        if (state.err) { state.err.textContent = ''; state.err.classList.add('pd2-d-none'); }
        if (state.authActions) state.authActions.classList.add('pd2-d-none');
        setLoading(true);

        fetch('?ajax=poster_admin_get_actions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ transaction_id: state.currentTxId }),
        })
            .then((r) => r.text())
            .then((t) => {
                const j = parseJsonLoose(t);
                if (!j || !j.ok) {
                    const msg = (j && j.error) ? String(j.error) : ('Ошибка: ' + String(t || '').slice(0, 300));
                    throw new Error(msg);
                }
                const p = j.params || {};
                const set = (k, v) => { if (state.fields[k]) state.fields[k].value = String(v != null ? v : ''); };
                set('payedSum', p.payedSum ?? 0);
                set('payedCash', p.payedCash ?? 0);
                set('payedCard', p.payedCard ?? 0);
                set('payedCert', p.payedCert ?? 0);
                set('payedEwallet', p.payedEwallet ?? 0);
                set('payedBonus', p.payedBonus ?? 0);
                set('payedThirdParty', p.payedThirdParty ?? 0);
                if (state.fields.usesPayedCert) state.fields.usesPayedCert.checked = Number(p.usesPayedCert ?? 0) === 1;

                const recalc = () => {
                    const parts = [
                        readInt(state.fields.payedCash),
                        readInt(state.fields.payedCard),
                        readInt(state.fields.payedCert),
                        readInt(state.fields.payedEwallet),
                        readInt(state.fields.payedBonus),
                        readInt(state.fields.payedThirdParty),
                    ];
                    const sum = parts.reduce((a, b) => a + (Number(b) || 0), 0);
                    if (state.fields.payedSum) state.fields.payedSum.value = String(sum);
                };
                ['payedCash', 'payedCard', 'payedCert', 'payedEwallet', 'payedBonus', 'payedThirdParty'].forEach((k) => {
                    const el = state.fields[k];
                    if (!el) return;
                    el.oninput = recalc;
                });
                recalc();
            })
            .catch((e) => {
                const msg = e && e.message ? String(e.message) : 'Ошибка';
                if (state.err) {
                    state.err.textContent = msg;
                    state.err.classList.remove('pd2-d-none');
                }
                const needAuth = /redirect http|session expired|\blogin\b|не настроены cookies|заполните настройки/i.test(msg);
                if (needAuth && state.authActions) state.authActions.classList.remove('pd2-d-none');
            })
            .finally(() => {
                setLoading(false);
                state.opening = false;
            });
    };

    window.pd2OpenPaytypeEdit = open;
    document.addEventListener('pd2_edit_check', (e) => {
        const txId = Number(e && e.detail && e.detail.txId ? e.detail.txId : 0) || 0;
        if (!txId) return;
        open(txId);
    });

    const onEditBtnClick = (e) => {
        const trg = (e && e.target instanceof Element)
            ? e.target
            : (e && e.target && e.target.parentElement instanceof Element ? e.target.parentElement : null);
        const btn = trg && trg.closest ? trg.closest('.pd2-check-edit-btn') : null;
        if (!btn) return;
        const txId = Number(btn.getAttribute('data-edit-check') || 0) || 0;
        if (!txId) return;
        try { e.preventDefault(); } catch (_) {}
        open(txId);
    };
    document.addEventListener('click', onEditBtnClick, true);
    document.addEventListener('click', onEditBtnClick, false);
})();
