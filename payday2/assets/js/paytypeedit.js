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
                payedSum: Number(p.payedSum ?? 0) || 0,
            };
        }

        static computePayedSum(params) {
            const p = params && typeof params === 'object' ? params : {};
            const sum = (Number(p.payedCash) || 0)
                + (Number(p.payedCard) || 0)
                + (Number(p.payedCert) || 0);
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
            this.onChange = null;
            this.isLoading = false;
            this.canSave = true;
        }

        updateSaveState() {
            this.ensure();
            if (this.btnSave) this.btnSave.disabled = this.isLoading || !this.canSave;
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
                        <label class="pd2-settings-label pd2-m-0"><span>Общая сумма</span>
                            <input type="text" class="btn pd2-w-100" id="pte_payedSum" autocomplete="off" readonly>
                        </label>
                        <label class="pd2-settings-label pd2-m-0"><span>Кеш</span>
                            <input type="text" class="btn pd2-w-100" id="pte_payedCash" autocomplete="off">
                        </label>
                        <label class="pd2-settings-label pd2-m-0"><span>Картой</span>
                            <input type="text" class="btn pd2-w-100" id="pte_payedCard" autocomplete="off">
                        </label>
                        <label class="pd2-settings-label pd2-m-0"><span>Сертификатом</span>
                            <input type="text" class="btn pd2-w-100" id="pte_payedCert" autocomplete="off">
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
            this.isLoading = !!on;
            if (this.loadingEl) this.loadingEl.classList.toggle('pd2-d-none', !on);
            this.updateSaveState();
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

        setCanSave(canSave) {
            this.ensure();
            this.canSave = !!canSave;
            this.updateSaveState();
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
            const total = Number(p.payedSum ?? 0) || 0;
            set('payedSum', total);
            set('payedCash', p.payedCash ?? 0);
            set('payedCard', p.payedCard ?? 0);
            set('payedCert', p.payedCert ?? 0);
            const keepTotal = () => {
                if (!this.fields.payedSum) return;
                const t = String(total);
                if (this.fields.payedSum.value !== t) this.fields.payedSum.value = t;
            };
            ['payedCash', 'payedCard', 'payedCert'].forEach((k) => {
                const el = this.fields[k];
                if (!el) return;
                el.oninput = () => {
                    keepTotal();
                    if (typeof this.onChange === 'function') this.onChange();
                };
            });
        }

        getFormParams() {
            this.ensure();
            const params = {
                payedCash: this.readIntField(this.fields.payedCash),
                payedCard: this.readIntField(this.fields.payedCard),
                payedCert: this.readIntField(this.fields.payedCert),
            };
            return params;
        }
    }

    class PaytypeEditController {
        constructor(api, view) {
            this.api = api;
            this.view = view;
            this.txId = 0;
            this.total = 0;
            this.opening = false;
            this.saving = false;
            this.validationErrorActive = false;
            this.view.onSave = () => this.save().catch(() => {});
            this.view.onChange = () => this.validate();
        }

        needsAuthMessage(msg) {
            const s = String(msg || '');
            return /redirect http|session expired|\blogin\b|не настроены cookies|заполните настройки|access denied/i.test(s);
        }

        isNotEditableMessage(msg) {
            const s = String(msg || '');
            return /\bedit action not found\b/i.test(s);
        }

        validate() {
            const params = this.view.getFormParams();
            const sum = PosterAdminEditCheckModel.computePayedSum(params);
            const total = Number(this.total) || 0;
            const ok = sum === total;
            if (!ok) {
                this.view.setError('Общая сумма должна быть равна сумме: Кеш + Картой + Сертификатом. Сейчас: ' + String(sum) + '. Должна быть: ' + String(total) + '.');
                this.validationErrorActive = true;
            } else {
                if (this.validationErrorActive) this.view.clearError();
                this.validationErrorActive = false;
            }
            this.view.setCanSave(ok);
            return ok;
        }

        async open(transactionId) {
            const txId = Number(transactionId || 0) || 0;
            if (!txId || this.opening) return;
            this.txId = txId;
            this.total = 0;
            this.opening = true;
            try {
                const j = await this.api.getActions(txId);
                const params = PosterAdminEditCheckModel.fromParams(j.params || {});
                this.total = Number(params.payedSum || 0) || 0;
                this.view.show();
                this.view.setTxId(txId);
                this.view.clearError();
                this.view.setAuthActionsVisible(false);
                this.view.setCanSave(false);
                this.view.setLoading(false);
                this.view.setForm(params);
                this.validate();
            } catch (e) {
                const msg = e && e.message ? String(e.message) : 'Ошибка';
                if (this.isNotEditableMessage(msg)) {
                    if (typeof window.showToast === 'function') window.showToast('Этот чек нередактируемый');
                    else window.alert('Этот чек нередактируемый');
                    this.view.hide();
                    this.validationErrorActive = false;
                    return;
                }
                this.view.show();
                this.view.setTxId(txId);
                this.view.setError(msg);
                this.validationErrorActive = false;
                if (this.needsAuthMessage(msg)) this.view.setAuthActionsVisible(true);
            } finally {
                this.opening = false;
            }
        }

        async save() {
            if (!this.txId || this.saving) return;
            if (!this.validate()) return;
            this.saving = true;
            this.view.setLoading(true);
            this.view.clearError();
            try {
                const params = this.view.getFormParams();
                const sum = PosterAdminEditCheckModel.computePayedSum(params);
                if (sum !== this.total) {
                    throw new Error('Общая сумма должна быть равна сумме: Кеш + Картой + Сертификатом. Сейчас: ' + String(sum) + '. Должна быть: ' + String(this.total) + '.');
                }
                await this.api.editCheck(this.txId, params);
                if (typeof window.showToast === 'function') window.showToast('Сохранено');
                this.view.hide();
                try { window.dispatchEvent(new CustomEvent('pd2_checks_reload')); } catch (_) {}
            } catch (e) {
                const msg = e && e.message ? String(e.message) : 'Ошибка';
                this.view.setError(msg);
                this.validationErrorActive = false;
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
