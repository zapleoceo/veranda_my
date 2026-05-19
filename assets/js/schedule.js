// /schedule — пока что только help-mode toggle (как на /payday3).
// Когда UX-каркас подтверждён — сюда подключаются:
//   - SortableJS для drag-n-drop смен между ячейками
//   - inline-edit popover при клике на ячейку
//   - period picker (sync date inputs ↔ URL)
//   - AJAX-эндпоинты: ?ajax=load|save_snapshot|list_halls
//   - live-пересчёт прогноза ЗП и coverage warnings

'use strict';

(() => {
    const btn = document.getElementById('schHelpBtn');
    if (!btn) return;
    let on = false;
    btn.addEventListener('click', () => {
        on = !on;
        document.body.classList.toggle('sch-help-mode', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    // Demo-mode notice: фейк-кнопки логируют действие, не выполняют.
    document.querySelectorAll('[data-demo-noop]').forEach((el) => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            const label = el.getAttribute('data-demo-noop') || el.textContent.trim();
            console.info(`[schedule:demo] noop: ${label}`);
        });
    });
})();
