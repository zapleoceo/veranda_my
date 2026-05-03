(() => {
    const ce = (tag) => document.createElement(tag);
    const digitsOnly = (s) => String(s || '').replace(/\D+/g, '');
    const parseJsonLoose = (text) => {
        try { return JSON.parse(String(text || '')); } catch (_) { return null; }
    };

    class PosterAdminEditCheckModel {
        static fromParams(rawParams) {
            const p = rawParams && typeof rawParams === 'object' ? rawParams : {};
            return {
                payedCash: Number(p.payedCash ?? 0) || 0,
                payedCard: Number(p.payedCard ?? 0) || 0,
                payedCert: Number(p.payedCert ?? 0) || 0,
                payedEwallet: Number(p.payedEwallet ?? 0) || 0,
                payedBonus: Number(p.payedBonus ?? 0) || 0,
                payedThirdParty: Number(p.payedThirdParty ?? 0) || 0,
                usesPayedCert: Number(p.usesPayedCert ?? 0) === 1 ? 1 : 0,
            };
        }

        static computePayedSum(params) {
            const p = params && typeof params === 'object' ? params : {};
            const sum = (Number(p.payedCash) || 0)
                + (Number(p.payedCard) || 0)
                + (Number(p.payedCert) || 0)
                + (Number(p.payedEwallet) || 0)
                + (Number(p.payedBonus) || 0)
                + (Number(p.payedThirdParty) || 0);
            return sum;
        }
    }

    class PosterAdminApi {
        async getActions(transactionId) {
            const txId = Number(transactionId || 0) || 0;
            if (!txId) throw new Error('transaction_id пустой');
            const r = await fetch('?ajax=poster_admin_get_actions', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ transaction_id: txId }),
            });
            const t = await r.text();
            const j = parseJsonLoose(t);
            if (!j || !j.ok) {
                const msg = (j && j.error) ? String(j.error) : ('Ошибка: ' + String(t || '').slice(0, 300));
                throw new Error(msg);
            }
            return j;
        }

        async editCheck(transactionId, params) {
            const txId = Number(transactionId || 0) || 0;
            if (!txId) throw new Error('transaction_id пустой');
            const r = await fetch('?ajax=poster_admin_edit_check', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ transaction_id: txId, params }),
            });
            const t = await r.text();
            const j = parseJsonLoose(t);
            if (!j || !j.ok) {
                const msg = (j && j.error) ? String(j.error) : ('Ошибка: ' + String(t || '').slice(0, 300));
                throw new Error(msg);
            }
            return j;
        }
    }

    class PaytypeEditModalView {
        constructor() {
            this.modal = null;
            this.err = null;
            this.txIdEl = null;
            this.loadingEl = null;
            this.authActions = null;
            this.btnSave = null;
            this.fields = {};
            this.onSave = null;
        }

        ensure() {
            if (this.modal) return;
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
                    <h3 class="pd2-m-0">Редактирование оплаты</h3>
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

            this.modal = backdrop;
            this.err = modal.querySelector('#paytypeEditErr');
            this.txIdEl = modal.querySelector('#paytypeEditTxId');
            this.loadingEl = modal.querySelector('#paytypeEditLoading');
            this.authActions = modal.querySelector('#paytypeEditAuthActions');
            this.btnSave = modal.querySelector('#paytypeEditSave');
            this.fields = {
                payedSum: modal.querySelector('#pte_payedSum'),
                payedCash: modal.querySelector('#pte_payedCash'),
                payedCard: modal.querySelector('#pte_payedCard'),
                payedCert: modal.querySelector('#pte_payedCert'),
                payedEwallet: modal.querySelector('#pte_payedEwallet'),
                payedBonus: modal.querySelector('#pte_payedBonus'),
                payedThirdParty: modal.querySelector('#pte_payedThirdParty'),
                usesPayedCert: modal.querySelector('#pte_usesPayedCert'),
            };

            const btnClose = modal.querySelector('#paytypeEditClose');
            const btnCancel = modal.querySelector('#paytypeEditCancel');
            const close = () => this.hide();
            if (btnClose) btnClose.addEventListener('click', close);
            if (btnCancel) btnCancel.addEventListener('click', close);
            this.modal.addEventListener('click', (e) => { if (e.target === this.modal) close(); });
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && this.modal && this.modal.style.display === 'flex') close(); });

            const btnOpenPosterLogin = modal.querySelector('#paytypeEditOpenPosterLogin');
            const btnOpenSettings = modal.querySelector('#paytypeEditOpenSettings');

            const openPosterLogin = () => {
                let acc = '';
                try {
                    const ls = window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.localSettings ? window.PAYDAY_CONFIG.localSettings : null;
                    acc = ls && ls.poster_admin && ls.poster_admin.account ? String(ls.poster_admin.account).trim() : '';
                } catch (_) {}
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

            if (btnOpenPosterLogin) btnOpenPosterLogin.addEventListener('click', openPosterLogin);
            if (btnOpenSettings) btnOpenSettings.addEventListener('click', openSettings);

            if (this.btnSave) {
                this.btnSave.addEventListener('click', () => {
                    if (typeof this.onSave === 'function') this.onSave();
                });
            }
        }

        show() {
            this.ensure();
            if (this.modal) this.modal.style.display = 'flex';
        }

        hide() {
            this.ensure();
            if (this.modal) this.modal.style.display = 'none';
            this.clearError();
            this.setAuthActionsVisible(false);
        }

        setTxId(txId) {
            this.ensure();
            if (this.txIdEl) this.txIdEl.textContent = 'tx_id: ' + String(txId || '');
        }

        setLoading(on) {
            this.ensure();
            if (this.loadingEl) this.loadingEl.classList.toggle('pd2-d-none', !on);
            if (this.btnSave) this.btnSave.disabled = !!on;
        }

        setError(msg) {
            this.ensure();
            if (!this.err) return;
            this.err.textContent = String(msg || '');
            this.err.classList.toggle('pd2-d-none', !String(msg || '').trim());
        }

        clearError() {
            this.setError('');
        }

        setAuthActionsVisible(on) {
            this.ensure();
            if (this.authActions) this.authActions.classList.toggle('pd2-d-none', !on);
        }

        readIntField(el) {
            if (!el) return 0;
            const d = digitsOnly(el.value);
            return d ? (Number(d) || 0) : 0;
        }

        setForm(params) {
            this.ensure();
            const set = (k, v) => { if (this.fields[k]) this.fields[k].value = String(v != null ? v : ''); };
            const p = params && typeof params === 'object' ? params : {};
            set('payedCash', p.payedCash ?? 0);
            set('payedCard', p.payedCard ?? 0);
            set('payedCert', p.payedCert ?? 0);
            set('payedEwallet', p.payedEwallet ?? 0);
            set('payedBonus', p.payedBonus ?? 0);
            set('payedThirdParty', p.payedThirdParty ?? 0);
            if (this.fields.usesPayedCert) this.fields.usesPayedCert.checked = Number(p.usesPayedCert ?? 0) === 1;
            this.recalcSum();

            const recalc = () => this.recalcSum();
            ['payedCash', 'payedCard', 'payedCert', 'payedEwallet', 'payedBonus', 'payedThirdParty'].forEach((k) => {
                const el = this.fields[k];
                if (!el) return;
                el.oninput = recalc;
            });
        }

        recalcSum() {
            this.ensure();
            const params = this.getFormParams();
            const sum = PosterAdminEditCheckModel.computePayedSum(params);
            if (this.fields.payedSum) this.fields.payedSum.value = String(sum);
        }

        getFormParams() {
            this.ensure();
            const params = {
                payedCash: this.readIntField(this.fields.payedCash),
                payedCard: this.readIntField(this.fields.payedCard),
                payedCert: this.readIntField(this.fields.payedCert),
                payedEwallet: this.readIntField(this.fields.payedEwallet),
                payedBonus: this.readIntField(this.fields.payedBonus),
                payedThirdParty: this.readIntField(this.fields.payedThirdParty),
                usesPayedCert: this.fields.usesPayedCert && this.fields.usesPayedCert.checked ? 1 : 0,
            };
            params.payedSum = PosterAdminEditCheckModel.computePayedSum(params);
            return params;
        }
    }

    class PaytypeEditController {
        constructor(api, view) {
            this.api = api;
            this.view = view;
            this.txId = 0;
            this.opening = false;
            this.saving = false;
            this.view.onSave = () => this.save().catch(() => {});
        }

        needsAuthMessage(msg) {
            const s = String(msg || '');
            return /redirect http|session expired|\blogin\b|не настроены cookies|заполните настройки|access denied/i.test(s);
        }

        async open(transactionId) {
            const txId = Number(transactionId || 0) || 0;
            if (!txId || this.opening) return;
            this.txId = txId;
            this.opening = true;
            this.view.show();
            this.view.setTxId(txId);
            this.view.clearError();
            this.view.setAuthActionsVisible(false);
            this.view.setLoading(true);
            try {
                const j = await this.api.getActions(txId);
                const params = PosterAdminEditCheckModel.fromParams(j.params || {});
                this.view.setForm(params);
            } catch (e) {
                const msg = e && e.message ? String(e.message) : 'Ошибка';
                this.view.setError(msg);
                if (this.needsAuthMessage(msg)) this.view.setAuthActionsVisible(true);
            } finally {
                this.view.setLoading(false);
                this.opening = false;
            }
        }

        async save() {
            if (!this.txId || this.saving) return;
            this.saving = true;
            this.view.setLoading(true);
            this.view.clearError();
            try {
                const params = this.view.getFormParams();
                await this.api.editCheck(this.txId, params);
                if (typeof window.showToast === 'function') window.showToast('Сохранено');
                this.view.hide();
                try { window.dispatchEvent(new CustomEvent('pd2_checks_reload')); } catch (_) {}
            } catch (e) {
                const msg = e && e.message ? String(e.message) : 'Ошибка';
                this.view.setError(msg);
                if (this.needsAuthMessage(msg)) this.view.setAuthActionsVisible(true);
            } finally {
                this.view.setLoading(false);
                this.saving = false;
            }
        }
    }

    const api = new PosterAdminApi();
    const view = new PaytypeEditModalView();
    const controller = new PaytypeEditController(api, view);
    window.pd2OpenPaytypeEdit = (txId) => controller.open(txId).catch(() => {});
})();
