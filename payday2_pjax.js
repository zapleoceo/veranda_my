if (!window._paydayPjaxLoaded) {
    window._paydayPjaxLoaded = true;

    window.doPjax = async function(url, options = {}) {
        document.body.style.opacity = '0.5';
        try {
            const res = await fetch(url, options);
            if (res.redirected) {
                url = res.url;
            }
            const html = await res.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            const newContainer = doc.querySelector('.container');
            const oldContainer = document.querySelector('.container');
            if (newContainer && oldContainer) {
                oldContainer.innerHTML = newContainer.innerHTML;
            } else {
                document.body.innerHTML = doc.body.innerHTML;
            }

            const configScript = Array.from(doc.querySelectorAll('script')).find(s => s.textContent.includes('window.PAYDAY_CONFIG ='));
            if (configScript) {
                eval(configScript.textContent);
            }

            if (options.method !== 'POST' || res.redirected) {
                window.history.pushState({}, '', url);
            }

            if (typeof window.initPayday2 === 'function') {
                window.initPayday2();
            }
        } catch (e) {
            console.error('PJAX Error:', e);
            if (options.method !== 'POST') {
                window.location.href = url;
            }
        } finally {
            document.body.style.opacity = '1';
        }
    };

    const _realAddEventListenerDoc = document.addEventListener;
    document.addEventListener = function(type, listener, options) {
        if (!window._paydayPjaxListeners) window._paydayPjaxListeners = [];
        // Only track listeners that are NOT from this pjax script
        if (listener.name !== 'pjaxListener') {
            window._paydayPjaxListeners.push({type, listener, options});
        }
        return _realAddEventListenerDoc.call(document, type, listener, options);
    };

    const _realAddEventListenerWin = window.addEventListener;
    window.addEventListener = function(type, listener, options) {
        if (!window._paydayPjaxWinListeners) window._paydayPjaxWinListeners = [];
        if (listener.name !== 'pjaxListener') {
            window._paydayPjaxWinListeners.push({type, listener, options});
        }
        return _realAddEventListenerWin.call(window, type, listener, options);
    };

    window.clearPaydayListeners = function() {
        if (window._paydayPjaxListeners) {
            window._paydayPjaxListeners.forEach(l => {
                document.removeEventListener(l.type, l.listener, l.options);
            });
            window._paydayPjaxListeners = [];
        }
        if (window._paydayPjaxWinListeners) {
            window._paydayPjaxWinListeners.forEach(l => {
                window.removeEventListener(l.type, l.listener, l.options);
            });
            window._paydayPjaxWinListeners = [];
        }
    };

    const clickHandler = function pjaxListener(e) {
        const a = e.target.closest('a');
        if (a && a.href && a.href.includes('/payday2') && !a.hasAttribute('download') && !a.target && !e.ctrlKey && !e.metaKey) {
            e.preventDefault();
            window.doPjax(a.href);
        }
    };
    _realAddEventListenerDoc.call(document, 'click', clickHandler);

    const popstateHandler = function pjaxListener() {
        window.doPjax(window.location.href);
    };
    _realAddEventListenerWin.call(window, 'popstate', popstateHandler);

    const submitHandler = function pjaxListener(e) {
        const form = e.target;
        if (e.defaultPrevented) return;
        
        const action = form.getAttribute('action') || window.location.href;
        const method = (form.getAttribute('method') || 'GET').toUpperCase();
        
        if (action.includes('/payday2') || action.startsWith('?') || !action.includes('//')) {
            e.preventDefault();
            
            const formData = new FormData(form);
            if (method === 'GET') {
                const url = new URL(action, window.location.origin);
                for (const [k, v] of formData.entries()) {
                    url.searchParams.set(k, v);
                }
                window.doPjax(url.href);
            } else {
                window.doPjax(action, {
                    method: 'POST',
                    body: formData
                });
            }
        }
    };
    _realAddEventListenerDoc.call(document, 'submit', submitHandler);
}
