// /schedule — help-mode + tooltip rendering.
//
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

    // ─── Help mode toggle ───────────────────────────────
    let on = false;
    btn.addEventListener('click', () => {
        on = !on;
        document.body.classList.toggle('sch-help-mode', on);
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (!on) hideTip();
    });

    // ─── Floating tooltip ───────────────────────────────
    // Сам тултип живёт в body, не в родителе элемента, поэтому
    // overflow:auto / overflow:hidden родителей его не клипают.
    const tip = document.createElement('div');
    tip.className = 'sch-help-tip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);

    let currentTarget = null;

    function showTip(el) {
        const txt = el.getAttribute('data-help-abs');
        if (!txt) return;
        currentTarget = el;

        // Сначала запихиваем текст и делаем видимым, чтобы измерить
        // финальные размеры с учётом переноса строк.
        tip.textContent = txt;
        tip.classList.add('visible');
        tip.style.left = '-9999px';
        tip.style.top  = '0px';

        // Force reflow → measure
        const tw = tip.offsetWidth;
        const th = tip.offsetHeight;

        const r  = el.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const margin = 8;
        const gap = 12; // расстояние от элемента до тултипа

        // Горизонталь: центрируем над элементом, но clamp в viewport
        let left = r.left + r.width / 2 - tw / 2;
        if (left < margin) left = margin;
        if (left + tw > vw - margin) left = vw - tw - margin;

        // Вертикаль: сверху если есть место, иначе снизу
        let top;
        let arrow;
        const spaceAbove = r.top;
        const spaceBelow = vh - r.bottom;
        if (spaceAbove >= th + gap || spaceAbove >= spaceBelow) {
            top = r.top - th - gap;
            arrow = 'above';
            if (top < margin) top = margin;
        } else {
            top = r.bottom + gap;
            arrow = 'below';
            if (top + th > vh - margin) top = vh - th - margin;
        }

        // Стрелочка к элементу (горизонтальная позиция по центру элемента)
        const arrowLeft = r.left + r.width / 2 - left;
        tip.style.setProperty('--arrow-left', arrowLeft + 'px');
        tip.setAttribute('data-arrow', arrow);

        tip.style.left = left + 'px';
        tip.style.top  = top + 'px';
    }

    function hideTip() {
        tip.classList.remove('visible');
        currentTarget = null;
    }

    // Делегируем mouseover/out на document — работает для любых
    // элементов с [data-help-abs], даже добавленных динамически.
    document.addEventListener('mouseover', (e) => {
        if (!document.body.classList.contains('sch-help-mode')) return;
        const el = e.target.closest('[data-help-abs]');
        if (el && el !== currentTarget) showTip(el);
    });
    document.addEventListener('mouseout', (e) => {
        if (!document.body.classList.contains('sch-help-mode')) return;
        const el = e.target.closest('[data-help-abs]');
        if (!el) return;
        // Если уходим в дочерний элемент того же data-help — оставляем
        const to = e.relatedTarget && e.relatedTarget.closest('[data-help-abs]');
        if (to === el) return;
        hideTip();
    });

    // Скрываем тултип на скролле/resize, иначе он висит «в воздухе»,
    // потому что position: fixed координаты считаются относительно
    // viewport на момент показа.
    window.addEventListener('scroll', hideTip, true);
    window.addEventListener('resize', hideTip);

    // ─── Demo-noop кнопок (заглушки до реализации save/ajax) ──
    document.querySelectorAll('[data-demo-noop]').forEach((el) => {
        el.addEventListener('click', (e) => {
            e.preventDefault();
            const label = el.getAttribute('data-demo-noop') || el.textContent.trim();
            console.info(`[schedule:demo] noop: ${label}`);
        });
    });
})();
