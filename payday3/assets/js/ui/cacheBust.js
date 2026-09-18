// Cache-busting import helper.
//
// content.php loads index.js?v=<mtime>; every module forwards that
// `v=` to the modules it imports (dynamic import), so one bump
// invalidates the whole tree. Each module pulls this helper in with the
// same `v` and gets an importer bound to its own URL:
//
//   const _i = (await import(new URL('./cacheBust.js' + new URL(import.meta.url).search,
//                                    import.meta.url).href)).importer(import.meta.url);
//   const { api } = await _i('../api.js');
//
// Pure (no DOM), so it loads under node:test.

'use strict';

/** '?v=<version>' taken from a module URL, or '' when it has none. */
export function versionQuery(metaUrl) {
    const v = new URL(metaUrl).searchParams.get('v') || '';
    return v ? '?v=' + encodeURIComponent(v) : '';
}

/** Resolve `path` against `metaUrl`, carrying its `v=` along. */
export function versionedUrl(path, metaUrl) {
    return new URL(path + versionQuery(metaUrl), metaUrl).href;
}

/** `(path) => import(...)` for the module at `metaUrl`. */
export function importer(metaUrl) {
    return (path) => import(versionedUrl(path, metaUrl));
}
