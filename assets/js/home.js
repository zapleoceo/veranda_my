/*
 * /home — поведение страницы (defer; DOM готов).
 * Данные афиши берутся из DOM (data-* на карточках дня) — в JS не дублируются.
 */
(function () {
    'use strict';

    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var fine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    // ── 1. Floating nav: уплотнение при скролле ──────────────────
    var nav = document.getElementById('nav');
    if (nav) {
        var onScroll = function () {
            nav.classList.toggle('is-scrolled', window.scrollY > 40);
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    // ── 2. Афиша: пере-выбор «сегодня» на клиенте ────────────────
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

    // ── 3. Reveal при скролле (fade-up + blur, стаггер в CSS) ─────
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

    // ── 4. Магнитные кнопки (только мышь, без reduced-motion) ─────
    if (fine && !reduce) {
        document.querySelectorAll('[data-magnetic]').forEach(function (el) {
            el.addEventListener('pointermove', function (e) {
                var r = el.getBoundingClientRect();
                var dx = (e.clientX - (r.left + r.width / 2)) * 0.18;
                var dy = (e.clientY - (r.top + r.height / 2)) * 0.3;
                el.style.transform = 'translate(' + dx.toFixed(1) + 'px,' + dy.toFixed(1) + 'px)';
            });
            el.addEventListener('pointerleave', function () { el.style.transform = ''; });
        });
    }

    // ── 5. Переключатель языка (заглушка — пока живой только RU) ──
    document.querySelectorAll('.hdr__lang button, [data-lang]').forEach(function (b) {
        if (!b.hasAttribute('data-lang')) return;
        b.addEventListener('click', function () {
            var l = b.getAttribute('data-lang');
            if (l && l !== 'ru') {
                alert('Скоро: ' + l.toUpperCase() + '. Пока главная только на русском.');
            }
        });
    });

    // ── 6. Слайдер-галереи в «мирах» (crossfade + точки) ─────────
    document.querySelectorAll('[data-gallery]').forEach(function (g) {
        var slides = g.querySelectorAll('.gallery__slide');
        var dots = g.querySelectorAll('.gallery__dot');
        if (slides.length < 2) return;

        var i = 0, timer = null;
        function show(n) {
            n = (n + slides.length) % slides.length;
            slides[i].classList.remove('is-active');
            if (dots[i]) dots[i].classList.remove('is-active');
            i = n;
            slides[i].classList.add('is-active');
            if (dots[i]) dots[i].classList.add('is-active');
        }
        function start() { if (reduce) return; stop(); timer = setInterval(function () { show(i + 1); }, 4200); }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }

        dots.forEach(function (d, n) { d.addEventListener('click', function () { show(n); start(); }); });
        if (fine) { g.addEventListener('pointerenter', stop); g.addEventListener('pointerleave', start); }
        start();
    });
})();
