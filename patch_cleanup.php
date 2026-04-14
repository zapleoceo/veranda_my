<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$patch = <<<HTML
window.initPayday2 = function() {
    if (!window._realAddEventListenerDoc) {
        window._realAddEventListenerDoc = document.addEventListener;
        window._paydayListenersDoc = [];
        document.addEventListener = function(type, listener, options) {
            window._paydayListenersDoc.push({type, listener, options});
            return window._realAddEventListenerDoc.call(document, type, listener, options);
        };
        window.clearPaydayListenersDoc = function() {
            window._paydayListenersDoc.forEach(l => {
                document.removeEventListener(l.type, l.listener, l.options);
            });
            window._paydayListenersDoc = [];
        };
    }
    if (!window._realAddEventListenerWin) {
        window._realAddEventListenerWin = window.addEventListener;
        window._paydayListenersWin = [];
        window.addEventListener = function(type, listener, options) {
            window._paydayListenersWin.push({type, listener, options});
            return window._realAddEventListenerWin.call(window, type, listener, options);
        };
        window.clearPaydayListenersWin = function() {
            window._paydayListenersWin.forEach(l => {
                window.removeEventListener(l.type, l.listener, l.options);
            });
            window._paydayListenersWin = [];
        };
    }
    window.clearPaydayListenersDoc();
    window.clearPaydayListenersWin();

    window.__USER_EMAIL__ = window.PAYDAY_CONFIG.userEmail;
HTML;

$js = str_replace("window.initPayday2 = function() {\n    window.__USER_EMAIL__ = window.PAYDAY_CONFIG.userEmail;", $patch, $js);

// Also undo my previous fix_leak attempt that broke syntax
$js = preg_replace("/if\(window\._hChange\) document\.removeEventListener\('change', window\._hChange\);\n    window\._hChange = \(ev\) => \{/", "document.addEventListener('change', (ev) => {", $js);
$js = preg_replace("/if\(window\._hClick\) document\.removeEventListener\('click', window\._hClick\);\n    window\._hClick = \(ev\) => \{/", "document.addEventListener('click', (ev) => {", $js);
$js = preg_replace("/if\(window\._hKd\) document\.removeEventListener\('keydown', window\._hKd\);\n    window\._hKd = \(e\) => \{/", "document.addEventListener('keydown', (e) => {", $js);

file_put_contents('payday2/assets/js/payday2.js', $js);
