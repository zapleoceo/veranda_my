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

    // ── 2. Афиша: клик по дню недели → меняет featured (текст+фон+ссылку) ──
    (function () {
        var today = new Date().getDay();
        var bgs = document.querySelectorAll('.tonight__feature-bg [data-bg]');
        var frontIdx = 0;
        var wanted = null;
        var dayEl = document.getElementById('tonightDay');
        var titleEl = document.getElementById('tonightTitle');
        var noteEl = document.getElementById('tonightNote');
        var ctaEl = document.getElementById('tonightCta');
        var badgeEl = document.getElementById('tonightBadge');
        var cards = document.querySelectorAll('.tonight__day-card');
        if (!cards.length) return;

        // Кроссфейд между двумя слоями: новое фото грузится в задний слой и
        // проявляется поверх старого. Никаких «скачков» и мелькания прошлого фото.
        function setBg(name) {
            if (!name || bgs.length < 2) return;
            var front = bgs[frontIdx];
            var cur = front.currentSrc || front.src || '';
            wanted = name;
            if (cur.indexOf('/' + name + '-') > -1) return; // уже показан
            var back = bgs[1 - frontIdx];
            var large = '/assets/img/home/' + name + '-1400.webp';
            var pre = new Image();
            pre.onload = pre.onerror = function () {
                if (wanted !== name) return; // был более поздний клик — не перетираем
                back.removeAttribute('srcset');
                back.src = large;
                back.classList.add('is-active');
                front.classList.remove('is-active');
                frontIdx = 1 - frontIdx;
            };
            pre.src = large;
        }

        function select(card) {
            if (!card) return;
            var d = card.dataset;
            cards.forEach(function (c) { c.classList.remove('is-active'); });
            card.classList.add('is-active');
            if (dayEl) dayEl.textContent = d.dayname;
            if (titleEl) titleEl.textContent = d.title + ' · ' + d.time;
            if (noteEl) noteEl.textContent = d.note;
            if (ctaEl && d.url) ctaEl.href = d.url;
            if (badgeEl) badgeEl.textContent = (Number(d.day) === today ? 'Сегодня вечером' : 'В афише');
            setBg(d.image);
        }

        cards.forEach(function (c) { c.addEventListener('click', function () { select(c); }); });
        select(document.querySelector('.tonight__day-card[data-day="' + today + '"]') || cards[0]);
    })();

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
        function start() { if (reduce) return; stop(); timer = setInterval(function () { show(i + 1); }, 2800); }
        function stop() { if (timer) { clearInterval(timer); timer = null; } }

        dots.forEach(function (d, n) { d.addEventListener('click', function () { show(n); start(); }); });
        if (fine) { g.addEventListener('pointerenter', stop); g.addEventListener('pointerleave', start); }
        start();
    });

    // ── 7. Видео детского мира: грузим и играем только в зоне видимости ──
    //    (preload="none" + .play() по пересечению; на мобиле video display:none →
    //     не пересекается → видео не грузится, остаётся фото).
    if (!reduce && 'IntersectionObserver' in window) {
        var vio = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) { e.target.play().catch(function () {}); }
                else { e.target.pause(); }
            });
        }, { rootMargin: '200px 0px' });
        document.querySelectorAll('video[data-video]').forEach(function (v) { vio.observe(v); });
    }
})();
