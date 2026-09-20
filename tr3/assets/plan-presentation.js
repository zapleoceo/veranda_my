(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.TR3PlanPresentation = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';
  const gazebos = new Set(['1', '2', '3', '6', '9']);
  function classify(hallId, item) {
    if (String(hallId) !== '2' || !item) return null;
    const number = String(item.schemeNum == null ? '' : item.schemeNum).trim();
    const label = String(item.label || '').trim();
    if (!item.bookable) {
      if (/^bar$/i.test(label)) return 'bar';
      if (/^(kashier|cashier)$/i.test(label)) return 'cashier';
      if (/^(stage|musicians|[🎵🎶🎼🎹🎸🥁🎤\s\uFE0F]+)$/u.test(label)) return 'stage';
      return null;
    }
    if (gazebos.has(number)) return 'gazebo';
    if (number === '4') return 'glass';
    if (/^1[0-3]$/.test(number)) return 'counter';
    if (/^(?:[1-9]|1\d|2[0-2])$/.test(number)) return 'wood';
    return 'room';
  }
  function deriveScene(hallId, items, width, height) {
    if (String(hallId) !== '2' || !Array.isArray(items) || !Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) return null;
    const valid = items.filter(i => i && ['x', 'y', 'w', 'h'].every(k => Number.isFinite(i[k])) && i.w > 0 && i.h > 0);
    const terraceItems = valid.filter(i => /^(1\d|2[0-2])$/.test(String(i.schemeNum)) || ['bar', 'cashier', 'stage'].includes(classify(hallId, i)));
    const garden = valid.filter(i => /^[1-9]$/.test(String(i.schemeNum)) && i.bookable);
    if (!terraceItems.length || !garden.length) return null;
    const unit = Math.min(...garden.map(i => Math.min(i.w, i.h)));
    const margin = unit * 0.16;
    const clamp = (value, maximum) => Math.max(0, Math.min(maximum, value));
    const edge = clamp(Math.max(...terraceItems.map(i => i.y + i.h)) + margin, height);
    const lawn = { x: 0, y: edge, w: width, h: height - edge };
    const lowerTerrace = valid.filter(i => /^(1[0-6])$/.test(String(i.schemeNum)));
    const upperTerrace = valid.filter(i => /^(1[7-9]|2[0-2])$/.test(String(i.schemeNum)));
    const gazeboOne = garden.find(i => String(i.schemeNum) === '1');
    let lawnNotch = null;
    let terraceClip = null;
    let fountain = null;
    if (lowerTerrace.length && upperTerrace.length && gazeboOne) {
      const notchX = clamp(Math.max(...lowerTerrace.map(i => i.x + i.w)) + margin, width);
      const notchY = clamp(Math.max(...upperTerrace.map(i => i.y + i.h)) + margin, height);
      // Keep the complete lower terrace on tile and raise the lawn on its right.
      const overlapsTerrace = terraceItems.some(i => i.x + i.w > notchX && i.y + i.h > notchY);
      if (notchX < gazeboOne.x && notchY < gazeboOne.y && notchY < edge && !overlapsTerrace) {
        lawnNotch = { x: notchX, y: notchY, w: width - notchX, h: edge - notchY };
        terraceClip = `polygon(0 0, 100% 0, 100% ${notchY}px, ${notchX}px ${notchY}px, ${notchX}px 100%, 0 100%)`;
        // The fountain belongs below17, right of10 and above1; never a remote corner.
        const x = (notchX + gazeboOne.x) / 2;
        const y = (notchY + gazeboOne.y) / 2;
        let radius = Math.min((gazeboOne.x - notchX) / 2, (gazeboOne.y - notchY) / 2, unit * 0.65) - margin / 2;
        valid.forEach(i => {
          const dx = Math.max(i.x - x, 0, x - i.x - i.w);
          const dy = Math.max(i.y - y, 0, y - i.y - i.h);
          radius = Math.min(radius, Math.hypot(dx, dy) - margin);
        });
        if (radius >= unit * 0.20) fountain = { x: x - radius, y: y - radius, w: radius * 2, h: radius * 2 };
      }
    }
    return { terrace: { x: 0, y: 0, w: width, h: edge }, lawn, lawnNotch, terraceClip, fountain };
  }

  function decorate(options) {
    const { hallId, tablesEl, decorEl, items, width, height, translate } = options;
    if (!tablesEl || !decorEl || String(hallId) !== '2') return false;
    const scene = deriveScene(hallId, items, width, height);
    if (!scene) return false;
    const doc = tablesEl.ownerDocument;
    const make = (name, parent) => {
      const el = doc.createElement('span');
      el.className = 'plan-' + name;
      el.setAttribute('aria-hidden', 'true');
      if (parent) parent.appendChild(el);
      return el;
    };
    const ground = make('ground');
    const place = (name, box) => {
      if (!box) return null;
      const el = make(name, ground);
      ['left', 'top', 'width', 'height'].forEach((key, n) => { el.style[key] = box[['x', 'y', 'w', 'h'][n]] + 'px'; });
      return el;
    };
    place('lawn', scene.lawn);
    const notch = place('lawn', scene.lawnNotch);
    if (notch) notch.classList.add('plan-lawn-notch');
    const terrace = place('terrace', scene.terrace);
    if (scene.terraceClip) terrace.style.clipPath = scene.terraceClip;
    const fountain = place('fountain', scene.fountain);
    if (fountain) ['ripple', 'ripple delay', 'koi one', 'koi two', 'jet'].forEach(n => make(n, fountain));
    const additions = [];
    items.forEach(item => {
      const role = classify(hallId, item);
      if (!role || !item.element) return;
      const art = make('art');
      if (role === 'wood' && String(item.schemeNum) === '8') art.classList.add('plan-live-edge');
      if (role === 'gazebo') ['cushions', 'curtain left', 'curtain right', 'posts', 'surface'].forEach(n => make(n, art));
      else if (role === 'counter') ['counter-chair first', 'counter-chair second', 'counter-slab'].forEach(n => make(n, art));
      else if (role === 'wood' || role === 'glass') {
        ['seat left', 'seat right', 'surface'].forEach(n => make(n, art));
        if (role === 'glass') make('parasol', art);
      } else if (role === 'cashier') make('terminal', art);
      else if (role === 'stage') ['speaker left', 'speaker right', 'keyboard'].forEach(n => make(n, art));
      if (['bar', 'cashier', 'stage'].includes(role)) {
        const label = make('plaque', art);
        label.textContent = typeof translate === 'function' ? translate(role === 'stage' ? 'musicians' : role) : item.label;
      }
      additions.push({ element: item.element, art, role });
    });
    // Build off-DOM, then commit. Roll back additions if the decoration fails.
    const previous = Array.from(decorEl.childNodes);
    try {
      additions.forEach(a => { a.element.appendChild(a.art); a.element.classList.add('plan-table', 'plan-' + a.role); });
      decorEl.replaceChildren(ground);
      tablesEl.classList.add('plan-tables');
      return true;
    } catch (_) {
      additions.forEach(a => { a.art.remove(); a.element.classList.remove('plan-table', 'plan-' + a.role); });
      decorEl.replaceChildren(...previous);
      return false;
    }
  }
  return { classify, deriveScene, decorate };
});
