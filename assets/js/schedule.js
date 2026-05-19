// /schedule — full UI скрипт.
//
// Что внутри:
//   • help-mode toggle + floating tooltip (рендер в body, не клипается)
//   • cell click → inline popover (employee/time/hall + сохранить/удалить)
//   • slot × → удалить конкретную колонку (с конфирмом)
//   • + add block → modal с двумя радиокартами (Hall / Custom zone)
//   • heatmap bucket selector → перебуцкетизация на лету по data-attrs
//   • basic native HTML5 drag-n-drop для смен между ячейками
//
// AJAX-эндпоинты (save_snapshot / load / list_halls / save_zone) добавятся
// в следующей итерации — пока кнопки помечены data-demo-noop и логируют.

'use strict';

(() => {

    // ════════════════ Help mode + tooltip ════════════════
    const helpBtn = document.getElementById('schHelpBtn');
    if (helpBtn) {
        let helpOn = false;
        helpBtn.addEventListener('click', () => {
            helpOn = !helpOn;
            document.body.classList.toggle('sch-help-mode', helpOn);
            helpBtn.setAttribute('aria-pressed', helpOn ? 'true' : 'false');
            if (!helpOn) hideTip();
        });
    }

    const tip = document.createElement('div');
    tip.className = 'sch-help-tip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);
    let tipTarget = null;

    function showTip(el) {
        const txt = el.getAttribute('data-help-abs');
        if (!txt) return;
        tipTarget = el;
        tip.textContent = txt;
        tip.classList.add('visible');
        tip.style.left = '-9999px'; tip.style.top = '0px';
        const tw = tip.offsetWidth, th = tip.offsetHeight;
        const r = el.getBoundingClientRect();
        const vw = window.innerWidth, vh = window.innerHeight;
        const margin = 8, gap = 12;
        let left = r.left + r.width / 2 - tw / 2;
        if (left < margin) left = margin;
        if (left + tw > vw - margin) left = vw - tw - margin;
        let top, arrow;
        const spaceAbove = r.top, spaceBelow = vh - r.bottom;
        if (spaceAbove >= th + gap || spaceAbove >= spaceBelow) {
            top = r.top - th - gap; arrow = 'above';
            if (top < margin) top = margin;
        } else {
            top = r.bottom + gap; arrow = 'below';
            if (top + th > vh - margin) top = vh - th - margin;
        }
        tip.setAttribute('data-arrow', arrow);
        tip.style.left = left + 'px';
        tip.style.top  = top + 'px';
    }
    function hideTip() {
        tip.classList.remove('visible');
        tipTarget = null;
    }
    document.addEventListener('mouseover', (e) => {
        if (!document.body.classList.contains('sch-help-mode')) return;
        const el = e.target.closest('[data-help-abs]');
        if (el && el !== tipTarget) showTip(el);
    });
    document.addEventListener('mouseout', (e) => {
        if (!document.body.classList.contains('sch-help-mode')) return;
        const el = e.target.closest('[data-help-abs]');
        if (!el) return;
        const to = e.relatedTarget && e.relatedTarget.closest && e.relatedTarget.closest('[data-help-abs]');
        if (to === el) return;
        hideTip();
    });
    window.addEventListener('scroll', hideTip, true);
    window.addEventListener('resize', hideTip);


    // ════════════════ Inline popover (cell edit) ════════════════
    const popover = document.getElementById('schPopover');
    const popoverMeta = document.getElementById('schPopoverMeta');
    const popoverEmp  = document.getElementById('schPopoverEmp');
    const popoverFrom = document.getElementById('schPopoverFrom');
    const popoverTo   = document.getElementById('schPopoverTo');
    const popoverHall = document.getElementById('schPopoverHall');
    let popoverAnchor = null;

    function showPopover(cell) {
        popoverAnchor = cell;
        const block = cell.dataset.block || '—';
        const slot  = cell.dataset.slot  || '—';
        const day   = cell.dataset.dayIdx || '—';
        popoverMeta.textContent = `Блок: ${block} · Слот: ${parseInt(slot, 10) + 1} · День: ${parseInt(day, 10) + 1}`;

        // Try to prefill from existing chip
        const chip = cell.querySelector('.sch-shift');
        if (chip) {
            const name = chip.querySelector('.sch-name')?.textContent || '';
            const time = chip.querySelector('.sch-time')?.textContent || '';
            // best-effort lookup of employee option by name (stripped of ★)
            const cleanName = name.replace('★', '').trim();
            for (const opt of popoverEmp.options) {
                if (opt.textContent.includes(cleanName) && cleanName) {
                    popoverEmp.value = opt.value;
                    break;
                }
            }
            const m = time.match(/(\d{1,2}):?(\d{0,2})\s*[–-]\s*(\d{1,2}):?(\d{0,2})/);
            if (m) {
                popoverFrom.value = `${m[1].padStart(2,'0')}:${(m[2]||'00').padStart(2,'0')}`;
                popoverTo.value   = `${m[3].padStart(2,'0')}:${(m[4]||'00').padStart(2,'0')}`;
            }
        }

        // Position the popover relative to cell, viewport-clamped
        popover.classList.add('visible');
        popover.style.left = '-9999px';
        popover.style.top = '0px';
        const pw = popover.offsetWidth, ph = popover.offsetHeight;
        const r = cell.getBoundingClientRect();
        const vw = window.innerWidth, vh = window.innerHeight;
        const margin = 10, gap = 10;
        let left = r.left + r.width / 2 - pw / 2;
        if (left < margin) left = margin;
        if (left + pw > vw - margin) left = vw - pw - margin;
        let top, arrow;
        if (vh - r.bottom >= ph + gap || vh - r.bottom >= r.top) {
            top = r.bottom + gap; arrow = 'above';
            if (top + ph > vh - margin) top = vh - ph - margin;
        } else {
            top = r.top - ph - gap; arrow = 'below';
            if (top < margin) top = margin;
        }
        popover.setAttribute('data-arrow', arrow);
        popover.style.left = left + 'px';
        popover.style.top  = top + 'px';
    }
    function hidePopover() {
        popover.classList.remove('visible');
        popoverAnchor = null;
    }
    document.addEventListener('click', (e) => {
        // Не в help-mode — клик по ячейке открывает попап
        if (document.body.classList.contains('sch-help-mode')) return;
        // Слот × ловится отдельным хэндлером ниже — не превращаем в открытие попапа
        if (e.target.closest('.sch-slot-del')) return;
        // Клик внутри попапа — игнорируем
        if (e.target.closest('#schPopover')) return;
        // Поиск ячейки
        const cell = e.target.closest('.sch-cell[data-block]');
        if (cell) {
            e.preventDefault();
            showPopover(cell);
            return;
        }
        // Клик за пределами активного попапа — закрытие
        if (popoverAnchor) hidePopover();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            hidePopover();
            closeModal();
        }
    });

    // Popover save/delete (demo)
    popover?.querySelector('.actions .save')?.addEventListener('click', () => {
        const emp = popoverEmp.value;
        const from = popoverFrom.value;
        const to = popoverTo.value;
        const hall = popoverHall.value;
        console.info('[schedule:demo] save shift', { emp, from, to, hall, cell: popoverAnchor?.dataset });
        if (popoverAnchor && emp) {
            // Visually update the cell for the demo
            const empName = popoverEmp.options[popoverEmp.selectedIndex].textContent.trim();
            const block = popoverAnchor.dataset.block;
            const cls = block === 'senior' ? 'senior' : block === 'banya' ? 'banya' : block === 'custom' ? 'custom' : 'main';
            const timeStr = `${from.replace(':00','')}–${to.replace(':00','')}`;
            popoverAnchor.innerHTML = `<div class="sch-shift ${cls}" draggable="true"><span class="sch-name">${escapeHtml(empName)}</span><span class="sch-time">${escapeHtml(timeStr)}</span></div>`;
        }
        hidePopover();
    });
    popover?.querySelector('.actions .del')?.addEventListener('click', () => {
        console.info('[schedule:demo] delete shift', { cell: popoverAnchor?.dataset });
        if (popoverAnchor) {
            popoverAnchor.innerHTML = '<span class="sch-empty">+</span>';
        }
        hidePopover();
    });
    document.querySelector('[data-demo-noop="popover-cancel"]')?.addEventListener('click', hidePopover);


    // ════════════════ Slot × delete ════════════════
    document.addEventListener('click', (e) => {
        const del = e.target.closest('.sch-slot-del');
        if (!del) return;
        e.preventDefault();
        e.stopPropagation();
        const id = del.getAttribute('data-demo-noop') || '';
        const ok = confirm('Удалить эту колонку-слот?\nЕсли в ней есть смены — они исчезнут.\n\n(' + id + ')');
        if (ok) {
            console.info('[schedule:demo] del-slot', id);
            // Демо: визуально не убираем колонку, нужна перерисовка грида под актуальные grid-template-columns
        }
    });


    // ════════════════ + Add block modal ════════════════
    const modal = document.getElementById('schModalAddBlock');
    const modalCloseBtn = document.getElementById('schModalAddBlockClose');
    const radioCards = document.querySelectorAll('#schBlockTypeRadio .card');
    const hallGroup   = document.getElementById('schBlockHallGroup');
    const customGroup = document.getElementById('schBlockCustomGroup');

    function openModal() {
        modal.classList.add('visible');
    }
    function closeModal() {
        modal?.classList.remove('visible');
    }
    document.querySelector('.sch-add-block-btn')?.addEventListener('click', (e) => {
        e.preventDefault();
        openModal();
    });
    modalCloseBtn?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
    radioCards.forEach((c) => {
        c.addEventListener('click', () => {
            radioCards.forEach((x) => x.classList.remove('active'));
            c.classList.add('active');
            const isHall = c.dataset.type === 'hall';
            hallGroup.style.display   = isHall ? '' : 'none';
            customGroup.style.display = isHall ? 'none' : '';
        });
    });


    // ════════════════ Heatmap bucket size selector ════════════════
    const bucketSel = document.getElementById('schBucketSize');
    if (bucketSel) {
        bucketSel.addEventListener('change', () => {
            console.info('[schedule:demo] bucket changed to', bucketSel.value);
            // Полноценная перерисовка heatmap-а под другой шаг — задача
            // следующей итерации (когда DA подключим через AJAX и SSR
            // заменим клиентским рендером). Пока шаг прибит на 2 часа
            // (рассчитывается в PHP в schedule_content.php).
            alert('Шаг heatmap = ' + bucketSel.value + ' час.\nДемо: серверный рендер сейчас фиксирован на 2 часа. Перебуцкетизация на лету — следующий этап.');
        });
    }


    // ════════════════ Drag & drop (native HTML5) ════════════════
    let dragSource = null;
    document.addEventListener('dragstart', (e) => {
        const chip = e.target.closest('.sch-shift');
        if (!chip) return;
        dragSource = chip.closest('.sch-cell');
        chip.style.opacity = '0.4';
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', dragSource.dataset.block + ':' + dragSource.dataset.slot + ':' + dragSource.dataset.dayIdx); } catch (_) {}
    });
    document.addEventListener('dragend', (e) => {
        const chip = e.target.closest('.sch-shift');
        if (chip) chip.style.opacity = '';
        dragSource = null;
    });
    document.addEventListener('dragover', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (!cell || !dragSource || cell === dragSource) return;
        e.preventDefault();
        cell.style.outline = '2px dashed var(--accent)';
    });
    document.addEventListener('dragleave', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (cell) cell.style.outline = '';
    });
    document.addEventListener('drop', (e) => {
        const cell = e.target.closest('.sch-cell[data-block]');
        if (!cell || !dragSource || cell === dragSource) return;
        e.preventDefault();
        cell.style.outline = '';
        // Move chip from source to target. If target has a chip — swap.
        const srcChip = dragSource.querySelector('.sch-shift');
        const dstChip = cell.querySelector('.sch-shift');
        if (!srcChip) return;
        const srcEmpty = '<span class="sch-empty">+</span>';
        const srcCellEmpty = dragSource.dataset.block === 'banya' || dragSource.dataset.block === 'custom'
            ? '<span class="sch-empty">—</span>' : srcEmpty;
        const dstCellEmpty = cell.dataset.block === 'banya' || cell.dataset.block === 'custom'
            ? '<span class="sch-empty">—</span>' : srcEmpty;

        // Adapt chip color class to new block context
        const newCls = cell.dataset.block === 'senior' ? 'senior'
                     : cell.dataset.block === 'banya'  ? 'banya'
                     : cell.dataset.block === 'custom' ? 'custom'
                     : 'main';
        const srcCls = dragSource.dataset.block === 'senior' ? 'senior'
                     : dragSource.dataset.block === 'banya'  ? 'banya'
                     : dragSource.dataset.block === 'custom' ? 'custom'
                     : 'main';

        srcChip.className = 'sch-shift ' + newCls;
        if (dstChip) {
            dstChip.className = 'sch-shift ' + srcCls;
            // swap nodes
            const srcHtml = dragSource.innerHTML;
            const dstHtml = cell.innerHTML;
            dragSource.innerHTML = dstHtml;
            cell.innerHTML = srcHtml;
        } else {
            cell.innerHTML = '';
            cell.appendChild(srcChip);
            dragSource.innerHTML = srcCellEmpty;
        }
        console.info('[schedule:demo] drop', {
            from: dragSource.dataset,
            to: cell.dataset,
        });
        dragSource = null;
    });


    // ════════════════ Demo-noop wiring for all marked controls ════════════════
    document.querySelectorAll('[data-demo-noop]').forEach((el) => {
        // Skip elements that have their own handlers above
        if (el.classList.contains('sch-slot-del')) return;
        if (el.id === 'schModalAddBlockClose') return;
        if (el.closest('#schPopover .actions')) return;
        el.addEventListener('click', (e) => {
            e.preventDefault();
            const label = el.getAttribute('data-demo-noop') || el.textContent.trim();
            console.info(`[schedule:demo] noop: ${label}`);
        });
    });


    // ════════════════ Helpers ════════════════
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }
})();
