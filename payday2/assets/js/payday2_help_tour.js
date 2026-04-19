(() => {
    const getEl = (sel) => {
        try { return document.querySelector(sel); } catch (_) { return null; }
    };
    const escapeHtml = (str) => {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };
    const clamp = (v, min, max) => Math.max(min, Math.min(max, v));

    const state = {
        idx: 0,
        overlay: null,
        box: null,
        titleEl: null,
        textEl: null,
        stepEl: null,
        btnPrev: null,
        btnNext: null,
        btnDone: null,
        currentTarget: null,
        started: false,
    };

    const steps = [
        {
            key: 'title',
            tab: 'in',
            selector: '#payday2InfoBtn',
            title: 'Payday2',
            text: 'Страница сверки денег: сравнение банка/почты (факт) и Poster (учет), чтобы быстро находить несоответствия и связывать операции.',
        },
        {
            key: 'help',
            tab: 'in',
            selector: '#payday2HelpToggleBtn',
            title: 'Справка',
            text: 'Этот пошаговый режим подсказок. Дальше/Назад переключают блоки, а «Закончить» закрывает обучение.',
        },
        {
            key: 'settings',
            tab: 'in',
            selector: '#payday2SettingsBtn',
            title: 'Настройки',
            text: 'Настройка Telegram и Poster (счета, разрешенные категории и их названия) — влияет на создание транзакций по «+» и отправку отчетов.',
            placement: 'bottom-right',
        },
        {
            key: 'btnKashShift',
            tab: 'in',
            selector: '#btnKashShift',
            title: 'KashShift',
            text: 'Просмотр кассовых смен из Poster. Помогает сверить кассу на конец дня.',
            placement: 'bottom-right',
        },
        {
            key: 'btnSupplies',
            tab: 'in',
            selector: '#btnSupplies',
            title: 'Поставки',
            text: 'Просмотр списка поставок из Poster.',
            placement: 'bottom-right',
        },
        {
            key: 'tabs',
            tab: 'in',
            selector: '.tabs',
            title: 'Режимы IN/OUT',
            text: 'IN — сверка приходов. OUT — сверка расходов/списаний. Логика похожая, но таблицы разные.',
        },
        {
            key: 'dates',
            tab: 'in',
            selector: '#topFormsWrap',
            title: 'Период',
            text: 'Выбор даты/периода, по которому грузятся данные. Меняйте дату — и перезагружайте нужные таблицы кнопками обновления.',
        },
        {
            key: 'sepayCard',
            tab: 'in',
            selector: '#sepayTable',
            title: 'Деньги (факт)',
            text: 'Банк/почта: реальные поступления. Тут выбираются строки для связывания с чеками Poster.',
            placement: 'top',
        },
        {
            key: 'sepayReload',
            tab: 'in',
            selector: '#sepaySyncBtn',
            title: 'Загрузить деньги',
            text: 'Обновляет список банковских поступлений за выбранную дату/период.',
        },
        {
            key: 'sepayHiddenEye',
            tab: 'in',
            selector: '#toggleSepayHiddenBtn',
            title: 'Скрытые',
            text: 'Показывает/скрывает скрытые строки (которые исключены из сверки).',
            placement: 'bottom-right',
        },
        {
            key: 'sepayMinusBtn',
            tab: 'in',
            selector: '.sepay-hide',
            title: 'Кнопка "Минус"',
            text: 'Слева от каждой строки есть кнопка «−». Она позволяет скрыть транзакцию, если она не относится к Poster (чтобы не мешала сверке).',
        },
        {
            key: 'liteFullToggle',
            tab: 'in',
            selector: '.pd2-toggle-wrap',
            title: 'Режим Lite / Full',
            text: 'Lite — компактный вид таблиц (скрыты некоторые столбцы для удобства на малых экранах). Full — полная информация по каждой транзакции.',
            placement: 'bottom',
            offsetTarget: '#midCol',
            extraClass: 'pd2-tour-target-wrap',
        },
        {
            key: 'inLinkControls',
            tab: 'in',
            selector: '#midCol',
            title: 'Связи (IN)',
            text: 'Блок управления связями.\n\nТут отображаются суммы выделенных чеков и разница между ними. Кнопки ниже позволяют:\n• связать выделенное вручную (🎯)\n• скрыть связанные (👁)\n• запустить автосвязь (🧩)\n• разорвать все связи (⛓️‍💥)',
        },
        {
            key: 'linkLines',
            tab: 'in',
            selector: '.mid-legend',
            title: 'Линии связей',
            text: 'Связанные строки соединяются цветными линиями. Зеленые/Желтые — автосвязь, Серые — ручная связь. На каждой линии есть крестик (×), чтобы разорвать конкретную связь.',
            placement: 'bottom',
            offsetTarget: '#midCol',
        },
        {
            key: 'softResetBtn',
            tab: 'in',
            selector: '#clearDayBtn',
            title: 'Soft reset',
            text: 'Сбрасывает загруженные транзакции за выбранную дату. При следующем обновлении таблиц данные загрузятся заново с нуля.',
            placement: 'bottom',
        },
        {
            key: 'posterChecks',
            tab: 'in',
            selector: '#posterTable',
            title: 'Poster чеки (учет)',
            text: 'Чеки из Poster (безнал/чаевые) за период. Их связываем с фактическими поступлениями из банка.',
            placement: 'top',
        },
        {
            key: 'posterReload',
            tab: 'in',
            selector: '#posterSyncBtn',
            title: 'Загрузить чеки Poster',
            text: 'Обновляет список чеков Poster за выбранную дату/период.',
        },
        {
            key: 'posterVietnamEye',
            tab: 'in',
            selector: '#toggleVietnamBtn',
            title: 'Фильтр Vietnam',
            text: 'Скрывает/показывает чеки Vietnam Company, чтобы быстрее сверять основную массу чеков.',
            placement: 'bottom-right',
        },
        {
            key: 'outTab',
            tab: 'out',
            selector: '#tabOut',
            title: 'Переходим в OUT',
            text: 'OUT — сверка расходов: факт (Деньги 📧) против транзакций Poster (Poster тр-ии).',
        },
        {
            key: 'outMoney',
            tab: 'out',
            selector: '#outSepayTable',
            title: 'Деньги 📧 (факт расходов)',
            text: 'Фактические списания. Тут выбираются строки для связывания с транзакциями расходов Poster.',
            placement: 'top',
        },
        {
            key: 'outReloadMail',
            tab: 'out',
            selector: '#outMailBtn',
            title: 'Загрузить из почты',
            text: 'Обновляет фактические списания (Деньги 📧).',
        },
        {
            key: 'outMinusBtn',
            tab: 'out',
            selector: '.out-hide',
            title: 'Кнопка "Минус"',
            text: 'Слева от каждой строки списания есть кнопка «−». Она позволяет скрыть транзакцию, если она не относится к Poster.',
        },
        {
            key: 'outPlusBtn',
            tab: 'out',
            selector: '.out-create-poster-tx-btn',
            title: 'Кнопка "Плюс"',
            text: 'Справа от суммы списания есть кнопка «+». Она позволяет быстро создать новую транзакцию расхода прямо в Poster на основе этой суммы.',
        },
        {
            key: 'outPosterTx',
            tab: 'out',
            selector: '#outPosterTable',
            title: 'Poster тр-ии',
            text: 'Транзакции расходов/переводов в Poster, которые нужно сопоставить с фактическими списаниями.',
            placement: 'top',
        },
        {
            key: 'outReloadPoster',
            tab: 'out',
            selector: '#outFinanceBtn',
            title: 'Загрузить из Poster',
            text: 'Обновляет таблицу Poster тр-ии за выбранную дату/период.',
        },
        {
            key: 'outLinkControls',
            tab: 'out',
            selector: '#outMidCol',
            title: 'Связи (OUT)',
            text: 'Блок управления связями в режиме OUT.\n\nПоказывает суммы выделенных строк в левой и правой таблицах и их разницу. Кнопки ниже позволяют:\n• связать выделенное вручную (🎯)\n• скрыть связанные (👁)\n• автосвязать за день (🧩)\n• разорвать все связи (⛓️‍💥)',
        },
        {
            key: 'financeBlock',
            tab: 'in',
            selector: '.card-finance',
            title: 'Финансовые транзакции',
            text: 'Создание/контроль сводных транзакций в Poster по итогам дня (например, переводы между счетами).',
        },
        {
            key: 'financeRefreshAll',
            tab: 'in',
            selector: '#finance-refresh-all',
            title: 'Обновить транзакции',
            text: 'Обновить статусы созданных финансовых транзакций (проверить, создались ли они в Poster).',
        },
        {
            key: 'balancesBlock',
            tab: 'in',
            selector: '.card-balances',
            title: 'Итоговый баланс',
            text: 'Сводка текущих балансов по счетам в Poster. Позволяет контролировать расхождения между расчетным и фактическим балансом. Поля фактического баланса вносятся руками.',
        },
        {
            key: 'balanceSync',
            tab: 'in',
            selector: '#balanceSyncBtn',
            title: 'UPLD',
            text: 'Сохранить или загрузить фактические балансы для сверки с расчетными (если предусмотрено API).',
        },
        {
            key: 'posterAccounts',
            tab: 'in',
            selector: '#posterAccountsBtn',
            title: 'Обновить балансы',
            text: 'Сделать запрос в Poster и получить актуальные текущие балансы по всем привязанным счетам.',
        },
        {
            key: 'balancesTelegram',
            tab: 'in',
            selector: '#posterBalancesTelegramBtn',
            title: 'Отправить в Telegram',
            text: 'Сформировать итоговый отчет по балансам и отправить его в привязанный Telegram-чат (указывается в Настройках).',
        },
    ];

    const ensureOverlay = () => {
        if (state.overlay) return;

        const overlay = document.createElement('div');
        overlay.className = 'pd2-tour-overlay pd2-d-none';
        overlay.innerHTML = `
            <div class="pd2-tour-dim"></div>
            <div class="pd2-tour-box" role="dialog" aria-modal="true">
                <div class="pd2-tour-head">
                    <div class="pd2-tour-title"></div>
                    <div class="pd2-tour-step"></div>
                </div>
                <div class="pd2-tour-text"></div>
                <div class="pd2-tour-actions">
                    <button type="button" class="btn2" data-action="prev">Назад</button>
                    <button type="button" class="btn2 primary" data-action="next">Далее</button>
                    <button type="button" class="btn2" data-action="done">Закончить</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        state.overlay = overlay;
        state.box = overlay.querySelector('.pd2-tour-box');
        state.titleEl = overlay.querySelector('.pd2-tour-title');
        state.textEl = overlay.querySelector('.pd2-tour-text');
        state.stepEl = overlay.querySelector('.pd2-tour-step');
        state.btnPrev = overlay.querySelector('[data-action="prev"]');
        state.btnNext = overlay.querySelector('[data-action="next"]');
        state.btnDone = overlay.querySelector('[data-action="done"]');

        overlay.addEventListener('click', (e) => {
            const btn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
            if (!btn) return;
            const a = btn.getAttribute('data-action');
            if (a === 'prev') go(-1);
            if (a === 'next') go(1);
            if (a === 'done') stop();
        });

        window.addEventListener('resize', () => {
            if (!state.started) return;
            render();
        });
        window.addEventListener('scroll', () => {
            if (!state.started) return;
            render();
        }, { passive: true });
        window.addEventListener('keydown', (e) => {
            if (!state.started) return;
            if (e.key === 'Escape') stop();
            if (e.key === 'ArrowRight') go(1);
            if (e.key === 'ArrowLeft') go(-1);
        });
    };

    const setTab = (tab) => {
        if (tab === 'out') {
            const t = document.getElementById('tabOut');
            if (t && !t.classList.contains('active')) t.click();
        } else if (tab === 'in') {
            const t = document.getElementById('tabIn');
            if (t && !t.classList.contains('active')) t.click();
        }
    };

    const clearHighlight = () => {
        if (state.currentTarget) {
            state.currentTarget.classList.remove('pd2-tour-target');
            state.currentTarget.classList.remove('pd2-tour-target-wrap');
            state.currentTarget = null;
        }
    };

    const findStepIndex = (idx, dir) => {
        let i = idx;
        while (i >= 0 && i < steps.length) {
            const s = steps[i];
            setTab(s.tab);
            const el = getEl(s.selector);
            if (el) return i;
            i += dir;
        }
        return idx;
    };

    const go = (delta) => {
        const nextIdx = findStepIndex(state.idx + delta, delta >= 0 ? 1 : -1);
        state.idx = clamp(nextIdx, 0, steps.length - 1);
        render(true);
    };

    const render = (scrollInto) => {
        ensureOverlay();
        const step = steps[state.idx];
        if (!step) return;

        document.body.classList.remove('pd2-help-mode');
        setTab(step.tab);

        const target = getEl(step.selector);
        if (!target) return;

        if (scrollInto) {
            try { target.scrollIntoView({ block: 'center', inline: 'center' }); } catch (_) {}
        }

        clearHighlight();
        if (step.extraClass) {
            target.classList.add(step.extraClass);
        } else {
            target.classList.add('pd2-tour-target');
        }
        state.currentTarget = target;

        state.titleEl.textContent = step.title || '';
        state.textEl.innerHTML = escapeHtml(step.text || '').replace(/\n/g, '<br>');
        state.stepEl.textContent = `${state.idx + 1}/${steps.length}`;

        state.btnPrev.disabled = state.idx === 0;
        state.btnNext.disabled = state.idx === steps.length - 1;

        const rect = target.getBoundingClientRect();
        const box = state.box;
        const vw = Math.max(document.documentElement.clientWidth, window.innerWidth || 0);
        const vh = Math.max(document.documentElement.clientHeight, window.innerHeight || 0);

        box.style.left = '0px';
        box.style.top = '0px';
        box.style.maxWidth = '360px';

        const boxRect = box.getBoundingClientRect();
        const gap = 12;

        const placement = step.placement || 'bottom';
        let top = 0;
        let left = 0;

        const placeBottom = () => {
            let baseRect = rect;
            if (step.offsetTarget) {
                const offEl = getEl(step.offsetTarget);
                if (offEl) baseRect = offEl.getBoundingClientRect();
            }
            top = baseRect.bottom + gap;
            left = rect.left + (rect.width / 2) - (boxRect.width / 2);
        };
        const placeTop = () => {
            top = rect.top - gap - boxRect.height;
            left = rect.left + (rect.width / 2) - (boxRect.width / 2);
        };
        const placeBottomRight = () => {
            top = rect.bottom + gap;
            left = rect.right - boxRect.width;
        };

        if (placement === 'bottom-right') placeBottomRight();
        else placeBottom();

        if (top + boxRect.height > vh - 8) {
            placeTop();
        }
        if (top < 8) {
            placeBottom();
        }

        left = clamp(left, 8, vw - boxRect.width - 8);
        top = clamp(top, 8, vh - boxRect.height - 8);

        box.style.left = `${left}px`;
        box.style.top = `${top}px`;
    };

    const start = () => {
        ensureOverlay();
        state.started = true;
        state.idx = findStepIndex(0, 1);
        state.overlay.classList.remove('pd2-d-none');
        render(true);
    };

    const stop = () => {
        state.started = false;
        clearHighlight();
        if (state.overlay) state.overlay.classList.add('pd2-d-none');
    };

    const init = () => {
        const btn = document.getElementById('payday2HelpToggleBtn');
        if (!btn) return;
        btn.addEventListener('click', () => {
            if (!state.started) start();
            else stop();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.PD2HelpTour = { start, stop };
})();

