(() => {
    const root = document.documentElement;
    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const details = document.querySelector('.lang-menu');
    const panelLinks = Array.from(document.querySelectorAll('.lang-panel a'));
    const content = document.getElementById('menuContent');
    if (!panelLinks.length || !content) return;

    const setActive = (lang) => {
        for (const a of panelLinks) {
            const href = a.getAttribute('href') || '';
            const m = href.match(/[?&]lang=([a-zA-Z-]+)/);
            const code = m ? m[1].toLowerCase() : '';
            a.classList.toggle('active', code === lang);
        }
    };

    const copyMeta = (fromDoc) => {
        const map = [
            ['title', 'title'],
            ['meta[name="description"]', 'meta[name="description"]'],
            ['link[rel="canonical"]', 'link[rel="canonical"]'],
            ['meta[property="og:title"]', 'meta[property="og:title"]'],
            ['meta[property="og:description"]', 'meta[property="og:description"]'],
            ['meta[property="og:url"]', 'meta[property="og:url"]'],
            ['meta[property="og:image"]', 'meta[property="og:image"]'],
            ['meta[name="twitter:title"]', 'meta[name="twitter:title"]'],
            ['meta[name="twitter:description"]', 'meta[name="twitter:description"]'],
            ['meta[name="twitter:image"]', 'meta[name="twitter:image"]'],
        ];

        for (const [selFrom, selTo] of map) {
            if (selTo === 'title') {
                document.title = fromDoc.title || document.title;
                continue;
            }
            const src = fromDoc.querySelector(selFrom);
            const dst = document.querySelector(selTo);
            if (!src || !dst) continue;
            if (dst.tagName === 'LINK') {
                const href = src.getAttribute('href');
                if (href) dst.setAttribute('href', href);
            } else {
                const content = src.getAttribute('content');
                if (content != null) dst.setAttribute('content', content);
            }
        }

        const jsonLd = fromDoc.querySelector('script[type="application/ld+json"]');
        const curJsonLd = document.querySelector('script[type="application/ld+json"]');
        if (jsonLd && curJsonLd) curJsonLd.textContent = jsonLd.textContent || '';
    };

    const animateSwap = (oldNode, newNode) => {
        if (reduced) {
            oldNode.replaceWith(newNode);
            return;
        }

        const parent = oldNode.parentElement;
        if (!parent) {
            oldNode.replaceWith(newNode);
            return;
        }

        const wrap = document.createElement('div');
        wrap.className = 'i18n-xfade';
        wrap.style.display = 'grid';

        const oldLayer = document.createElement('div');
        oldLayer.className = 'i18n-layer i18n-layer--old';
        oldLayer.appendChild(oldNode);

        const newLayer = document.createElement('div');
        newLayer.className = 'i18n-layer i18n-layer--new';
        newLayer.appendChild(newNode);

        parent.replaceChild(wrap, oldNode);
        wrap.append(oldLayer, newLayer);
        wrap.getBoundingClientRect();

        requestAnimationFrame(() => {
            if (!document.body.contains(wrap)) return;
            wrap.classList.add('is-animating');
        });

        window.setTimeout(() => {
            wrap.replaceWith(newNode);
        }, 280);
    };

    const captureState = () => {
        const cur = document.getElementById('menuContent');
        const openKeys = cur ? Array.from(cur.querySelectorAll('details[data-key][open]')).map((d) => String(d.getAttribute('data-key') || '')).filter(Boolean) : [];
        return { openKeys, scrollY: window.scrollY };
    };

    const applyState = (node, state) => {
        if (!node || !state) return;
        const openSet = new Set(Array.isArray(state.openKeys) ? state.openKeys : []);
        node.querySelectorAll('details[data-key]').forEach((d) => {
            const k = String(d.getAttribute('data-key') || '');
            if (openSet.has(k)) d.open = true;
        });
    };

    const loadLang = async (href) => {
        const state = captureState();
        const url = new URL(href, window.location.href);
        const lang = (url.searchParams.get('lang') || '').toLowerCase();
        if (!lang) return;

        try {
            const r = await fetch(url.toString(), { headers: { 'Accept': 'text/html' } });
            if (!r.ok) throw new Error('Bad response');
            const html = await r.text();
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const nextContent = doc.getElementById('menuContent');
            if (!nextContent) throw new Error('No content');

            nextContent.id = 'menuContent';
            applyState(nextContent, state);
            root.setAttribute('lang', doc.documentElement.getAttribute('lang') || lang);
            copyMeta(doc);
            setActive(lang);
            window.history.replaceState({}, '', url.toString());

            const current = document.getElementById('menuContent');
            if (!current) {
                window.location.href = url.toString();
                return;
            }

            animateSwap(current, nextContent);
            window.setTimeout(() => {
                const y = Number(state && state.scrollY != null ? state.scrollY : 0) || 0;
                window.scrollTo(0, y);
            }, 320);
        } catch (_) {
            window.location.href = url.toString();
        }
    };

    for (const a of panelLinks) {
        a.addEventListener('click', (e) => {
            if (a.classList.contains('active')) return;
            e.preventDefault();
            if (details) details.open = false;
            const href = a.getAttribute('href') || '';
            loadLang(href);
        });
    }
})();
