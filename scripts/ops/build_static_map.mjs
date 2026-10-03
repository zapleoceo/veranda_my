// Собирает статичную карту вокруг Veranda из тайлов OpenStreetMap.
// Запуск из корня репо (нужен sharp: npm i --no-save sharp): node scripts/ops/build_static_map.mjs assets/img/home
// Тайлы с tile.openstreetmap.org кешируются в <out>/tiles — их потом удалить.
import { createRequire } from 'node:module';
import fs from 'node:fs';
const require = createRequire(process.cwd() + '/package.json');
const sharp = require('sharp');

const LAT = 12.302584, LNG = 109.207279;
const OUT = process.argv[2];
const UA = 'veranda.my static map builder (one-off, https://veranda.my)';

function worldPx(lat, lng, z) {
  const n = 256 * 2 ** z, r = lat * Math.PI / 180;
  return [(lng + 180) / 360 * n, (1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * n];
}

async function tile(z, x, y) {
  const cache = `${OUT}/tiles/${z}-${x}-${y}.png`;
  if (fs.existsSync(cache)) return fs.readFileSync(cache);
  const res = await fetch(`https://tile.openstreetmap.org/${z}/${x}/${y}.png`, { headers: { 'User-Agent': UA } });
  if (!res.ok) throw new Error(`tile ${z}/${x}/${y}: HTTP ${res.status}`);
  const buf = Buffer.from(await res.arrayBuffer());
  fs.mkdirSync(`${OUT}/tiles`, { recursive: true });
  fs.writeFileSync(cache, buf);
  await new Promise(r => setTimeout(r, 300)); // вежливо к серверу OSM
  return buf;
}

async function build(z, w, h, name) {
  const [cx, cy] = worldPx(LAT, LNG, z);
  const left = cx - w / 2, top = cy - h / 2;
  const tx0 = Math.floor(left / 256), ty0 = Math.floor(top / 256);
  const tx1 = Math.floor((left + w) / 256), ty1 = Math.floor((top + h) / 256);
  const comps = [];
  for (let tx = tx0; tx <= tx1; tx++) for (let ty = ty0; ty <= ty1; ty++) {
    comps.push({ input: await tile(z, tx, ty), left: (tx - tx0) * 256, top: (ty - ty0) * 256 });
  }
  const mosaic = await sharp({ create: { width: (tx1 - tx0 + 1) * 256, height: (ty1 - ty0 + 1) * 256, channels: 3, background: '#000' } })
    .composite(comps).png().toBuffer();
  const crop = await sharp(mosaic)
    .extract({ left: Math.round(left - tx0 * 256), top: Math.round(top - ty0 * 256), width: w, height: h })
    .toBuffer();

  // Тёмная тёплая стилизация под палитру сайта (--bg #14100b). Попиксельно, т.к. у sharp
  // фиксированный порядок операций: светлое в OSM (дороги) → светлее, вода/лес → в фон.
  const { data, info } = await sharp(crop).greyscale().raw().toBuffer({ resolveWithObject: true });
  const lo = [24, 19, 13], hi = [150, 124, 88];
  const out = Buffer.alloc(info.width * info.height * 3);
  for (let i = 0; i < data.length; i++) {
    const t = Math.min(1, Math.max(0, (data[i] - 196) / 59)) ** 2.2;
    for (let c = 0; c < 3; c++) out[i * 3 + c] = Math.round(lo[c] + (hi[c] - lo[c]) * t);
  }
  await sharp(out, { raw: { width: info.width, height: info.height, channels: 3 } })
    .webp({ quality: 80 })
    .toFile(`${OUT}/${name}.webp`);
  console.log(`  ${name}.webp  z${z}  ${w}x${h}  тайлов: ${comps.length}`);
}

await build(15, 700, 525, 'map-veranda-700');
await build(16, 1400, 1050, 'map-veranda-1400');
