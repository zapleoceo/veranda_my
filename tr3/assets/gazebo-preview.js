(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.TR3GazeboPreview = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  function positionPreview(anchor, viewport, preferred = { width: 320, height: 240 }) {
    const gap = 12;
    const width = Math.max(0, Math.min(preferred.width, viewport.width - gap * 2));
    const height = Math.max(0, Math.min(preferred.height, viewport.height - gap * 2));
    const clamp = (value, maximum) => Math.max(gap, Math.min(maximum - gap, value));
    const right = anchor.right + gap;
    const left = right + width <= viewport.width - gap ? right : anchor.left - width - gap;
    return { left: clamp(left, viewport.width - width), top: clamp(anchor.top + (anchor.height - height) / 2, viewport.height - height), width, height };
  }
  function init(doc, win) {
    if (!doc || !win || doc.getElementById('tr3GazeboPreview')) return null;
    const portal = doc.createElement('div');
    portal.id = 'tr3GazeboPreview';
    portal.className = 'gazebo-preview';
    portal.hidden = true;
    portal.setAttribute('aria-hidden', 'true');
    const image = doc.createElement('img');
    image.alt = '';
    image.decoding = 'async';
    const caption = doc.createElement('span');
    caption.className = 'gazebo-preview-caption';
    const captions = { ru: 'Иллюстрация', en: 'Illustration', vi: 'Hình minh họa' };
    caption.setAttribute('data-i18n', 'preview_illustration');
    const refreshCaption = () => { caption.textContent = (win.STR && win.STR.preview_illustration) || captions[doc.documentElement.lang] || captions.en; };
    refreshCaption();
    portal.appendChild(image);
    portal.appendChild(caption);
    doc.body.appendChild(portal);
    let active = null;
    let pending = null;
    let touch = false;
    let failed = false;
    const listeners = [];
    const on = (target, type, handler, options) => {
      target.addEventListener(type, handler, options);
      listeners.push(() => target.removeEventListener(type, handler, options));
    };
    const hide = () => {
      if (pending !== null) win.clearTimeout(pending);
      pending = null;
      active = null;
      portal.hidden = true;
    };
    const targetTable = target => target && typeof target.closest === 'function' ? target.closest('#mapTablesMain .table.plan-gazebo') : null;
    const modalOpen = () => !!doc.querySelector('.modal.on, .modal[aria-hidden="false"], .dtp.on, .dtp[aria-hidden="false"], dialog[open]');
    const show = table => {
      hide();
      if (!table || failed || modalOpen() || table.closest('[hidden]')) return;
      refreshCaption();
      if (!image.getAttribute('src')) image.src = '/tr3/assets/gazebo-preview-v1.png';
      active = table;
      pending = win.setTimeout(() => {
        pending = null;
        if (active !== table || !table.isConnected || modalOpen()) return hide();
        const box = positionPreview(table.getBoundingClientRect(), { width: win.innerWidth, height: win.innerHeight });
        Object.keys(box).forEach(key => { portal.style[key] = box[key] + 'px'; });
        portal.hidden = false;
      }, 180);
    };
    on(doc, 'pointerover', event => {
      if (event.pointerType !== 'mouse' || !win.matchMedia('(any-hover: hover)').matches) return;
      const table = targetTable(event.target);
      if (table && !table.contains(event.relatedTarget)) show(table);
    });
    on(doc, 'pointerout', event => {
      if (active && active.contains(event.target) && !active.contains(event.relatedTarget)) hide();
    });
    on(doc, 'pointerdown', event => { touch = event.pointerType === 'touch'; hide(); }, true);
    on(doc, 'focusin', event => { if (!touch) show(targetTable(event.target)); });
    on(doc, 'focusout', hide);
    on(doc, 'keydown', event => { touch = false; if (event.key === 'Escape') hide(); });
    on(doc, 'click', hide, true);
    on(doc, 'scroll', hide, true);
    on(win, 'resize', hide);
    on(win, 'blur', hide);
    on(image, 'error', () => { failed = true; hide(); });
    const observer = new win.MutationObserver(records => {
      if (!active) return;
      if (!active.isConnected || modalOpen() || active.closest('[hidden]') || records.some(record => record.type === 'childList' && record.target.id === 'mapTablesMain')) hide();
    });
    observer.observe(doc.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class', 'hidden', 'aria-hidden'] });
    return { hide, destroy() { hide(); observer.disconnect(); listeners.forEach(remove => remove()); portal.remove(); } };
  }
  return { positionPreview, init };
});
