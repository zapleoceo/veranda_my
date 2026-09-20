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
  function deriveLandscape(scene, items, width, height) {
    const result = { shrubs: [], stones: [] };
    if (!scene || !scene.lawn || !Array.isArray(items) || !Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) return result;
    const valid = items.filter(i => i && ['x', 'y', 'w', 'h'].every(k => Number.isFinite(i[k])) && i.w > 0 && i.h > 0);
    const garden = valid.filter(i => /^[1-9]$/.test(String(i.schemeNum)) && i.bookable);
    if (!garden.length) return result;
    const unit = Math.min(...garden.map(i => Math.min(i.w, i.h)));
    const size = Math.max(12, unit * 0.55);
    const shrubHeight = Math.max(8, unit * 0.30);
    const clearance = unit * 0.045;
    const overlaps = (a, b, pad = clearance) => a.x < b.x + b.w + pad && a.x + a.w > b.x - pad && a.y < b.y + b.h + pad && a.y + a.h > b.y - pad;
    const inside = (a, b) => b && a.x >= b.x && a.y >= b.y && a.x + a.w <= b.x + b.w && a.y + a.h <= b.y + b.h;
    const inLawn = box => inside(box, scene.lawn) || inside(box, scene.lawnNotch);
    const stairs = [scene.steps, scene.stepsAfter13].filter(Boolean);
    const obstacles = valid.concat([scene.fountain, ...stairs].filter(Boolean));
    const clear = box => inLawn(box) && !obstacles.some(i => overlaps(box, i));
    // Short, continuous stepping routes start at each actual stair landing.
    // Local detours are bounded; stop rather than invent a route through furniture.
    stairs.forEach(stair => {
      let center = stair.x + stair.w / 2;
      const stoneW = Math.min(stair.w * 0.52, unit * 0.24);
      const stoneH = unit * 0.17;
      const stride = unit * 0.29;
      for (let n = 0; n < 8; n += 1) {
        const y = stair.y + stair.h + clearance * 2 + n * stride;
        const candidate = [0, -unit * 0.10, unit * 0.10].map(dx => ({ x: center + dx - stoneW / 2, y, w: stoneW, h: stoneH })).find(box => clear(box) && !result.stones.some(i => overlaps(box, i)));
        if (!candidate) break;
        result.stones.push(candidate);
        center = candidate.x + candidate.w / 2;
      }
    });
    // Separate paving across the clear lawn strip below the terrace. Stair
    // openings and furniture interrupt the strip rather than being painted over.
    const stoneStride = Math.max(unit * 0.42, width / 70);
    for (let x = unit * 0.25; x + unit * 0.29 < width; x += stoneStride) {
      const box = { x, y: scene.lawn.y + unit * 0.36, w: unit * 0.29, h: unit * 0.15 };
      if (clear(box) && !result.stones.some(i => overlaps(box, i))) result.stones.push(box);
    }
    const addShrub = (x, y, variant) => {
      const box = { x, y, w: size * (variant % 2 ? 1.12 : 1), h: shrubHeight * (variant % 3 ? 0.94 : 1), variant: variant % 4 };
      const stairOpening = stairs.some(i => overlaps(box, { x: i.x - size * 0.3, y: i.y, w: i.w + size * 0.6, h: i.h + size }));
      if (clear(box) && !stairOpening && !result.stones.some(i => overlaps(box, i, clearance * 0.6)) && !result.shrubs.some(i => overlaps(box, i, 1))) result.shrubs.push(box);
    };
    const edgeEnd = scene.lawnNotch ? scene.lawnNotch.x : width;
    const stride = Math.max(size * 1.12, width / 70);
    for (let x = size * 0.3, n = 0; x + size < edgeEnd; x += stride * (n % 3 === 0 ? 1.22 : 1), n += 1) addShrub(x, scene.lawn.y + 1 + (n % 3) * 0.45, n);
    if (scene.lawnNotch) {
      const notch = scene.lawnNotch;
      for (let y = notch.y + size * 0.5, n = 0; y + size < notch.y + notch.h; y += stride, n += 1) addShrub(notch.x + clearance + 4, y, n + 1);
    }
    // Sparse planting pockets along the lawn perimeter, never over furniture.
    for (let x = size, n = 0; x + size < width; x += stride * 2.5, n += 1) addShrub(x, height - shrubHeight - 3, n + 2);
    for (let y = scene.lawnNotch ? scene.lawnNotch.y + size : scene.lawn.y + size, n = 0; y + size < height; y += stride * 2.4, n += 1) {
      addShrub(width - size * 1.12 - 3, y, n);
      addShrub(3, y, n + 2);
    }
    return result;
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
        if (radius >= unit * 0.20) {
          const candidate = { x: notchX, y: notchY, w: radius * 2, h: radius * 2 };
          const blocked = valid.some(i => candidate.x < i.x + i.w && candidate.x + candidate.w > i.x && candidate.y < i.y + i.h && candidate.y + candidate.h > i.y);
          if (!blocked) fountain = candidate;
        }
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
    let stoneBed = null;
    if (tree && lawnNotch) {
      const trunkX = tree.x + tree.trunkX;
      const trunkY = tree.y + tree.trunkY;
      const candidate = { x: trunkX - unit * 0.30, y: trunkY - unit * 0.22,
        w: lawnNotch.x - trunkX + unit * 0.30, h: unit * 0.58 };
      const overlaps = valid.some(i => candidate.x < i.x + i.w && candidate.x + candidate.w > i.x && candidate.y < i.y + i.h && candidate.y + candidate.h > i.y);
      if (candidate.w > 0 && !overlaps) stoneBed = candidate;
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
    const scene = { terrace: { x: 0, y: 0, w: width, h: edge }, lawn, lawnNotch, terraceClip, fountain, driveway, tree, stoneBed, steps, stepsAfter13 };
    scene.landscape = deriveLandscape(scene, valid, width, height);
    return scene;
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
    scene.landscape.stones.forEach((box, index) => {
      const stone = place('stepping-stone', box);
      stone.classList.add('plan-stone-' + (index % 3));
    });
    scene.landscape.shrubs.forEach(box => {
      const shrub = place('shrub', box);
      shrub.classList.add('plan-shrub-' + box.variant);
    });
    const driveway = place('driveway', scene.driveway);
    if (driveway) {
      const parking = make('parking', driveway);
      parking.textContent = 'P';
      make('scooter', parking);
    }
    const bed = place('stone-bed', scene.stoneBed);
    if (bed) make('ochre-rock', bed);
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
  return { classify, deriveScene, deriveLandscape, decorate };
});
