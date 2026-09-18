// Lazy, integrity-checked html2canvas loader.
//
// The ~200 KB script is fetched only when the operator first clicks ✈
// (not pre-warmed on every page open) and is pinned with SRI: if cdnjs
// ever serves different bytes for this URL the browser refuses to run
// it. Hash = sha384 of exactly HTML2CANVAS_SRC.

'use strict';

export const HTML2CANVAS_SRC =
    'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
export const HTML2CANVAS_SRI =
    'sha384-ZZ1pncU3bQe8y31yfZdMFdSpttDoPmOZg2wguVK9almUodir1PghgT0eY7Mrty8H';

let _promise = null;

/** @returns {Promise<Function>} window.html2canvas */
export function loadHtml2Canvas() {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    if (_promise) return _promise;
    _promise = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = HTML2CANVAS_SRC;
        s.integrity = HTML2CANVAS_SRI;
        s.crossOrigin = 'anonymous';
        s.referrerPolicy = 'no-referrer';
        s.onload  = () => resolve(window.html2canvas);
        s.onerror = () => {
            _promise = null;
            s.remove();
            reject(new Error('html2canvas не загрузился (CDN заблокирован или не совпал integrity-хеш)'));
        };
        document.head.appendChild(s);
    });
    return _promise;
}
