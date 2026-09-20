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
    if (['4', '5', '7', '8'].includes(number)) return 'glass';
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
    const room = valid.find(i => /^room$/i.test(String(i.label || '').trim()));
    const driveX = room ? clamp(room.x + room.w, width) : width;
    const driveway = driveX < width ? { x: driveX, y: 0, w: width - driveX, h: lawnNotch ? lawnNotch.y : edge } : null;
    const ten = valid.find(i => String(i.schemeNum) === '10');
    const eleven = valid.find(i => String(i.schemeNum) === '11');
    const eighteen = valid.find(i => String(i.schemeNum) === '18');
    let tree = null;
    if (ten && eleven && eighteen && eighteen.y + eighteen.h < ten.y) {
      const x = eleven.x + eleven.w / 2;
      const top = eighteen.y + eighteen.h;
      tree = { x, y: top, w: Math.max(0, ten.x + ten.w - x), h: ten.y + ten.h - top,
        trunkX: ten.x + ten.w / 2 - x, trunkY: (ten.y - top) / 2 };
    }
    const twelve = valid.find(i => String(i.schemeNum) === '12');
    const createSteps = (x, w) => {
      const candidate = { x, y: edge - 8, w, h: Math.min(unit * 0.65, height - edge + 8) };
      const overlaps = valid.some(i => candidate.x < i.x + i.w && candidate.x + candidate.w > i.x && candidate.y < i.y + i.h && candidate.y + candidate.h > i.y);
      return x >= 0 && w > 0 && x + w <= width && candidate.h > 8 && !overlaps ? candidate : null;
    };
    let steps = null;
    let stairWidth = 0;
    if (eleven && twelve) {
      const left = Math.min(eleven.x + eleven.w, twelve.x + twelve.w);
      const right = Math.max(eleven.x, twelve.x);
      const gap = right - left;
      if (gap > margin) {
        stairWidth = gap - margin / 2;
        steps = createSteps(left + margin / 4, stairWidth);
      }
    }
    const thirteen = valid.find(i => String(i.schemeNum) === '13');
    const stepsAfter13 = thirteen ? createSteps(thirteen.x - margin / 2 - stairWidth, stairWidth) : null;
    return { terrace: { x: 0, y: 0, w: width, h: edge }, lawn, lawnNotch, terraceClip, fountain, driveway, tree, steps, stepsAfter13 };
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
    [scene.steps, scene.stepsAfter13].forEach(box => {
      const steps = place('steps', box);
      if (steps) for (let n = 0; n < 4; n += 1) make('step', steps);
    });
    const driveway = place('driveway', scene.driveway);
    if (driveway) {
      const parking = make('parking', driveway);
      parking.textContent = 'P';
      make('scooter', parking);
    }
    const tree = place('tree', scene.tree);
    if (tree) {
      make('canopy', tree);
      const trunk = make('trunk', tree);
      trunk.style.left = scene.tree.trunkX + 'px';
      trunk.style.top = scene.tree.trunkY + 'px';
    }
    const fountain = place('fountain', scene.fountain);
    if (fountain) ['ripple', 'ripple delay', 'koi one', 'koi two', 'jet'].forEach(n => make(n, fountain));
    const additions = [];
    items.forEach(item => {
      const role = classify(hallId, item);
      if (!role || !item.element) return;
      const art = make('art');
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
