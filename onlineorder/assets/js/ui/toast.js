/* Single transient toast, bottom-center. */

let timer = null;

export function toast(message, ms = 2600) {
    const el = document.getElementById('ooToast');
    if (!el) return;
    el.textContent = message;
    el.hidden = false;
    clearTimeout(timer);
    timer = setTimeout(() => { el.hidden = true; }, ms);
}
