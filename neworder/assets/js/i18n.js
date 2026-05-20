// Runtime translation lookup.
//
// The dictionary is rendered inline into the HTML (window.__noI18n) by
// NewOrderController, so JS modules just import t() and ask for keys.
// `t('foo')` returns the raw string; `t('foo', {q: 'x'})` interpolates
// `{q}` placeholders. Falls back to the key itself if missing so a
// half-translated dictionary never throws.

'use strict';

const DICT = (typeof window !== 'undefined' && window.__noI18n) || {};

export function t(key, vars) {
    let s = DICT[key];
    if (typeof s !== 'string') return key;
    if (vars) {
        for (const k in vars) {
            s = s.split('{' + k + '}').join(String(vars[k]));
        }
    }
    return s;
}

export const lang = (typeof window !== 'undefined' && window.__noLang) || 'ru';
