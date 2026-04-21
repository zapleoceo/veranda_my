window.initPayday2_Settings = function() {
    const payday2SettingsBtn = document.getElementById('payday2SettingsBtn');
    const payday2SettingsModal = document.getElementById('payday2SettingsModal');
    const payday2SettingsClose = document.getElementById('payday2SettingsClose');
    const payday2SettingsSave = document.getElementById('payday2SettingsSave');
    const payday2SettingsErr = document.getElementById('payday2SettingsErr');

    const escapeHtml = (s) => String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const fillPayday2SettingsForm = () => {
        const ls = (window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.localSettings) ? window.PAYDAY_CONFIG.localSettings : null;
        if (!ls) return;
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v !== undefined && v !== null ? String(v) : ''; };
        set('pd2sett_tg_chat', ls.telegram_chat_id);
        set('pd2sett_tg_thread', ls.telegram_message_thread_id);
        set('pd2sett_svc_user', ls.service_user_id);
        if (ls.accounts) {
            set('pd2sett_acc_andrey', ls.accounts.andrey);
            set('pd2sett_acc_tips', ls.accounts.tips);
            set('pd2sett_acc_vietnam', ls.accounts.vietnam);
        }
        set('pd2sett_balance_sinc', ls.balance_sinc_account_id);
        if (ls.poster_admin) {
            set('pd2sett_padm_account', ls.poster_admin.account);
            set('pd2sett_padm_pos_session', ls.poster_admin.pos_session);
            set('pd2sett_padm_ssid', ls.poster_admin.ssid);
            set('pd2sett_padm_csrf', ls.poster_admin.csrf);
            set('pd2sett_padm_ua', ls.poster_admin.user_agent);
        }
    };
    
    let payday2CategoriesLoaded = false;
    const loadPayday2Categories = () => {
        const listEl = document.getElementById('pd2sett_categories_list');
        if (!listEl || payday2CategoriesLoaded) return;
        
        fetch(location.pathname + '?ajax=finance_categories').then(r => r.json()).then(j => {
            if (!j || !j.ok) throw new Error(j.error || 'Ошибка');
            listEl.innerHTML = '';
            const ls = (window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.localSettings) ? window.PAYDAY_CONFIG.localSettings : null;
            const allowed = ls && ls.allowed_categories ? ls.allowed_categories : [];
            const customNames = ls && ls.custom_category_names ? ls.custom_category_names : {};
            
            const cats = j.categories || {};
            
            const roots = [];
            const byId = {};
            
            for (const [idStr, data] of Object.entries(cats)) {
                const id = Number(idStr);
                byId[id] = { id, name: data.name, parent_id: Number(data.parent_id || 0), children: [] };
            }
            
            for (const id in byId) {
                const node = byId[id];
                if (node.parent_id && byId[node.parent_id]) {
                    byId[node.parent_id].children.push(node);
                } else {
                    roots.push(node);
                }
            }
            
            const renderNode = (node, depth) => {
                const checked = allowed.includes(node.id) ? 'checked' : '';
                const customName = customNames[node.id] || '';
                const margin = depth * 20;
                
                let html = `
                    <div class="pd2-d-flex pd2-align-center pd2-gap-8 pd2-mb-6" style="margin-left: ${margin}px;">
                        <label class="pd2-d-flex pd2-align-center pd2-gap-8 pd2-pointer pd2-m-0">
                            <input type="checkbox" class="pd2-sett-cat-cb" value="${node.id}" ${checked}>
                            <span class="pd2-ws-nowrap">${escapeHtml(node.name)}</span>
                        </label>
                        <input type="text" class="btn pd2-sett-cat-name pd2-flex-1" data-id="${node.id}" value="${escapeHtml(customName)}" placeholder="${escapeHtml(node.name)}">
                    </div>
                `;
                
                for (const child of node.children) {
                    html += renderNode(child, depth + 1);
                }
                return html;
            };
            
            let html = '';
            for (const root of roots) {
                html += renderNode(root, 0);
            }
            
            listEl.innerHTML = html;
            payday2CategoriesLoaded = true;
        }).catch(e => {
            listEl.innerHTML = '<div class="error pd2-text-center">Ошибка загрузки категорий</div>';
        });
    };

    const readPayday2SettingsPayload = () => {
        const num = (id) => {
            const el = document.getElementById(id);
            const n = el ? parseInt(String(el.value || '').trim(), 10) : NaN;
            return Number.isFinite(n) ? n : 0;
        };
        const str = (id) => {
            const el = document.getElementById(id);
            return el ? String(el.value || '').trim() : '';
        };
        
        const ls = (window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.localSettings) ? window.PAYDAY_CONFIG.localSettings : null;
        
        let allowedCats = [];
        let customNames = {};
        
        // If categories were not loaded (user didn't open spoiler), preserve them from existing config
        if (!payday2CategoriesLoaded) {
            allowedCats = ls && ls.allowed_categories ? ls.allowed_categories : [];
            customNames = ls && ls.custom_category_names ? ls.custom_category_names : {};
        } else {
            // Save custom names for ALL categories that have an input value, regardless of whether checkbox is checked
            document.querySelectorAll('.pd2-sett-cat-name').forEach(input => {
                const id = Number(input.getAttribute('data-id'));
                const val = input.value.trim();
                if (val) {
                    customNames[id] = val;
                }
            });
            // Allowed categories only from checked checkboxes
            document.querySelectorAll('.pd2-sett-cat-cb:checked').forEach(cb => {
                allowedCats.push(Number(cb.value));
            });
        }

        return {
            telegram_chat_id: str('pd2sett_tg_chat'),
            telegram_message_thread_id: str('pd2sett_tg_thread'),
            service_user_id: num('pd2sett_svc_user'),
            accounts: {
                andrey: num('pd2sett_acc_andrey'),
                tips: num('pd2sett_acc_tips'),
                vietnam: num('pd2sett_acc_vietnam'),
            },
            balance_sinc_account_id: num('pd2sett_balance_sinc'),
            allowed_categories: allowedCats,
            custom_category_names: customNames,
            poster_admin: {
                account: str('pd2sett_padm_account'),
                pos_session: str('pd2sett_padm_pos_session'),
                ssid: str('pd2sett_padm_ssid'),
                csrf: str('pd2sett_padm_csrf'),
                user_agent: str('pd2sett_padm_ua'),
            },
        };
    };

    const openPayday2SettingsModal = () => {
        if (payday2SettingsErr) { payday2SettingsErr.textContent = ''; payday2SettingsErr.classList.add('pd2-d-none'); }
        fillPayday2SettingsForm();
        if (payday2SettingsModal) payday2SettingsModal.style.display = 'flex';
        
        const catSpoiler = document.getElementById('pd2sett_categories_spoiler');
        if (catSpoiler) {
            catSpoiler.addEventListener('toggle', () => {
                if (catSpoiler.open) loadPayday2Categories();
            });
        }
    };

    const closePayday2SettingsModal = () => {
        if (payday2SettingsModal) payday2SettingsModal.style.display = 'none';
    };

    if (payday2SettingsBtn) payday2SettingsBtn.addEventListener('click', openPayday2SettingsModal);
    if (payday2SettingsClose) payday2SettingsClose.addEventListener('click', closePayday2SettingsModal);
    
    if (payday2SettingsSave) {
        payday2SettingsSave.addEventListener('click', () => {
            const payload = readPayday2SettingsPayload();
            if (payday2SettingsErr) { payday2SettingsErr.textContent = ''; payday2SettingsErr.classList.add('pd2-d-none'); }
            payday2SettingsSave.disabled = true;
            fetch('?ajax=save_local_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            })
                .then((r) => r.json())
                .then((j) => {
                    if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка сохранения');
                    
                    if (window.PAYDAY_CONFIG) {
                        window.PAYDAY_CONFIG.localSettings = payload;
                        // Also update catNames flat map used by Poster table and others
                        window.PAYDAY_CONFIG.catNames = payload.custom_category_names || {};
                    }
                    closePayday2SettingsModal();
                    
                    // Reload categories view in settings modal to pick up new names next time
                    payday2CategoriesLoaded = false; 
                    
                    if (typeof window.showToast === 'function') {
                        window.showToast('Настройки сохранены');
                    } else {
                        alert('Настройки сохранены');
                    }
                })
                .catch((e) => {
                    if (payday2SettingsErr) {
                        payday2SettingsErr.textContent = e && e.message ? e.message : 'Ошибка';
                        payday2SettingsErr.classList.remove('pd2-d-none');
                    }
                })
                .finally(() => { payday2SettingsSave.disabled = false; });
        });
    }
    
    if (payday2SettingsModal) {
        payday2SettingsModal.addEventListener('click', (ev) => {
            if (ev.target === payday2SettingsModal) closePayday2SettingsModal();
        });
    }
    
    document.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Escape') return;
        if (payday2SettingsModal && payday2SettingsModal.style.display === 'flex') {
            closePayday2SettingsModal();
        }
    });
};
