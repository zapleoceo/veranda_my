/*
 * /home — поведение страницы. Подключается с defer (DOM готов).
 * Данные афиши берутся ИЗ DOM (data-* на карточках дня) — в JS их не дублируем.
 */
(function () {
    'use strict';

    // ── 1. Состояние шапки при скролле ───────────────────────────
    var hdr = document.getElementById('hdr');
    if (hdr) {
        var onScroll = function () {
            hdr.classList.toggle('is-scrolled', window.scrollY > 80);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    // ── 2. Афиша: пере-выбор «сегодня» на клиенте ────────────────
    // Сервер уже отрендерил сегодняшний день, но страница может быть
    // закеширована и показана на следующий день — подстрахуемся.
    var dayNames = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'];
    var today = new Date().getDay();
    var card = document.querySelector('.tonight__day-card[data-day="' + today + '"]');
    var dayEl = document.getElementById('tonightDay');
    var titleEl = document.getElementById('tonightTitle');
    var noteEl = document.getElementById('tonightNote');

    if (card && dayEl && titleEl && noteEl) {
        dayEl.textContent = dayNames[today];
        titleEl.textContent = card.dataset.title + ' · ' + card.dataset.time;
        noteEl.textContent = card.dataset.note;
    }
    document.querySelectorAll('.tonight__day-card').forEach(function (el) {
        el.classList.toggle('is-today', Number(el.getAttribute('data-day')) === today);
    });

    // ── 3. Появление секций при скролле ──────────────────────────
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if ('IntersectionObserver' in window && !reduce) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) {
                    e.target.classList.add('is-in');
                    io.unobserve(e.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
        document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
    } else {
        document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('is-in'); });
    }

    // ── 4. Переключатель языка (заглушка — пока живой только RU) ──
    document.querySelectorAll('.hdr__lang button').forEach(function (b) {
        b.addEventListener('click', function () {
            var l = b.getAttribute('data-lang');
            if (l !== 'ru') {
                alert('Скоро: ' + l.toUpperCase() + '. Пока главная только на русском.');
            }
        });
    });
})();
