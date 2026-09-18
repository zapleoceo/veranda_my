// «Настройки» modal — Telegram target, Poster account ids and the
// finance-category whitelist (+ custom names).
//
// Poster admin cookies are no longer stored or returned by the server
// (security audit); this form neither reads nor sends them.

'use strict';

const _i = (await import(new URL('../cacheBust.js' + new URL(import.meta.url).search, import.meta.url).href)).importer(import.meta.url);
const { api }       = await _i('../../api.js');
const { esc }       = await _i('../format.js');
const { setStatus } = await _i('../busy.js');
const { buildCategoryTree, walkCategories, customName } = await _i('../categoryTree.js');

/**
 * A pasted t.me link → Telegram chat / thread ids (pure, tested).
 *   https://t.me/c/123456/78 → { chatId: '-100123456', threadId: '78' }
 * @returns {{chatId:string, threadId:string}|null}
 */
export function parseTmeLink(value) {
    const m = String(value || '').match(/^https?:\/\/t\.me\/c\/(\d+)(?:\/(\d+))?/);
    return m ? { chatId: '-100' + m[1], threadId: m[2] || '' } : null;
}

// Last GET /settings payload, and the categories list (fetched once).
let settings = null;
let categories = null;

const $form   = () => document.getElementById('pd3SettingsForm');
const $status = () => document.getElementById('pd3SettingsStatus');
const ACCOUNT_KEYS = ['andrey', 'tips', 'vietnam', 'stash'];

async function load() {
    const form = $form();
    if (!form) return;
    setStatus($status(), '');
    try {
        const data = await api.get('/payday3/api/settings');
        settings = data || {};
        form.elements['telegram_chat_id'].value           = settings.telegram_chat_id || '';
        form.elements['telegram_message_thread_id'].value = settings.telegram_message_thread_id || '';
        form.elements['service_user_id'].value            = settings.service_user_id || '';
        const acc = settings.accounts || {};
        for (const k of ACCOUNT_KEYS) {
            const el = form.elements[`accounts[${k}]`];
            if (el) el.value = acc[k] || '';
        }
        form.elements['balance_sinc_account_id'].value = settings.balance_sinc_account_id || '';
        // Categories pane is lazy — only hydrate when the user opens it.
        wireCategoriesLazy();
    } catch (e) {
        setStatus($status(), 'Ошибка загрузки: ' + (e.message || 'error'), 'error');
    }
}

async function hydrateCategories() {
    const wrap = document.getElementById('pd3SettCategoriesList');
    if (!wrap || categories) return;
    wrap.innerHTML = '<div class="muted">Загрузка категорий…</div>';
    try {
        const cats = await api.get('/payday3/api/poster/finance/categories');
        categories = (cats && typeof cats === 'object') ? cats : {};
        renderCategories(wrap);
    } catch (e) {
        wrap.innerHTML = '<div class="muted">Не удалось загрузить категории: ' + esc(e.message || 'error') + '</div>';
    }
}

function renderCategories(wrap) {
    const allowed = new Set((settings?.allowed_categories || []).map(Number));
    const custom  = settings?.custom_category_names || {};
    // Depth-first like payday2_settings.js, so the visual hierarchy
    // matches what operators are used to.
    const { roots } = buildCategoryTree(categories);
    if (roots.length === 0) {
        wrap.innerHTML = '<div class="muted">Poster вернул пустой список категорий.</div>';
        return;
    }
    const parts = [];
    walkCategories(roots, (node, depth) => {
        const checked = allowed.has(node.id) ? 'checked' : '';
        parts.push(`<label class="pd3-settings__cat" style="padding-left:${depth * 16}px">
            <input type="checkbox" class="pd3-settings__cat-cb" data-cat-id="${node.id}" ${checked}>
            <span class="pd3-settings__cat-id">#${node.id}</span>
            <span class="pd3-settings__cat-name">${esc(node.name)}</span>
            <input type="text" class="pd3-settings__cat-rename" data-cat-id="${node.id}"
                   placeholder="${esc(node.name)}" value="${esc(customName(custom, node.id))}">
        </label>`);
    });
    wrap.innerHTML = parts.join('');
}

function wireCategoriesLazy() {
    // closest('details') rather than :has() — older Edge / Safari.
    const details = document.getElementById('pd3SettCategoriesList')?.closest('details');
    if (!details || details.dataset.bound === '1') return;
    details.dataset.bound = '1';
    details.addEventListener('toggle', () => { if (details.open) hydrateCategories(); });
    if (details.open) hydrateCategories();
}

async function save(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const status = $status();
    setStatus(status, 'Сохраняю…');
    const fd = new FormData(form);
    // Category state from the rendered list — or, if the pane was never
    // opened, the last-known server values.
    let cats;
    if (categories) {
        const allowed = Array.from(form.querySelectorAll('.pd3-settings__cat-cb:checked'))
            .map((el) => Number(el.dataset.catId) || 0).filter((n) => n > 0);
        const custom = {};
        form.querySelectorAll('.pd3-settings__cat-rename').forEach((el) => {
            const id = Number(el.dataset.catId) || 0;
            const v  = String(el.value || '').trim();
            if (id > 0 && v !== '') custom[id] = v;
        });
        cats = { allowed_categories: allowed, custom_category_names: custom };
    } else {
        cats = {
            allowed_categories:    settings?.allowed_categories    || [],
            custom_category_names: settings?.custom_category_names || {},
        };
    }

    const accounts = { ...(settings?.accounts || {}) };   // keeps ids without an input (e.g. cash)
    for (const k of ACCOUNT_KEYS) accounts[k] = Number(fd.get(`accounts[${k}]`)) || 0;

    const body = {
        telegram_chat_id:           fd.get('telegram_chat_id'),
        telegram_message_thread_id: fd.get('telegram_message_thread_id'),
        service_user_id:            Number(fd.get('service_user_id')) || 0,
        accounts,
        balance_sinc_account_id:    Number(fd.get('balance_sinc_account_id')) || 0,
        ...cats,
    };
    try {
        await api.post('/payday3/api/settings', body);
        settings = { ...settings, ...body };
        setStatus(status, 'Сохранено.', 'ok');
    } catch (e) {
        setStatus(status, 'Ошибка: ' + (e.message || 'error'), 'error');
    }
}

export function initSettings({ host }) {
    host.register('pd3SettingsModal', { trigger: 'pd3SettingsBtn', onOpen: load });
    $form()?.addEventListener('submit', save);

    // Paste a t.me URL into chat_id → split into chat + thread.
    const chatInput = document.querySelector('input[name="telegram_chat_id"]');
    chatInput?.addEventListener('input', () => {
        const link = parseTmeLink(chatInput.value);
        if (!link) return;
        chatInput.value = link.chatId;
        const th = document.querySelector('input[name="telegram_message_thread_id"]');
        if (link.threadId && th) th.value = link.threadId;
    });
}
