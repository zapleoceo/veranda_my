// Однократный билд: жмём отобранные iPhone-фото из ~/Downloads/photo/
// в WebP двух размеров и кладём в /assets/img/home/.
// Запускается руками (не в CI). Артефакты коммитятся в репо.

const sharp = require('sharp');
const fs    = require('fs');
const path  = require('path');

const SRC = 'D:/Downloads/photo';
const DST = path.join(__dirname, '..', 'assets', 'img', 'home');
if (!fs.existsSync(DST)) fs.mkdirSync(DST, { recursive: true });

// Отбор: source-file → out-name → назначение (заметка только для человека).
const PICKS = [
    // ── Hero / atmosphere ──────────────────────────────────────
    ['Atmosphere/IMG_1504.jpeg', 'hero-terrace',    'Длинная терраса с красными фонарями + вид на Нячанг'],
    ['Atmosphere/IMG_1261.jpeg', 'hero-lanterns',   'Фонари + гора, столики'],
    ['Atmosphere/IMG_1318.jpeg', 'lanterns-city',   'Красные фонари + высотки города'],
    ['Atmosphere/IMG_1229.jpeg', 'bougainvillea',   'Бугенвиллия + плетёные стулья'],
    ['Atmosphere/IMG_1335.jpeg', 'garden-table',    'Столик в розовом обрамлении'],
    ['Atmosphere/IMG_1393.jpeg', 'complex',         'Общий план комплекса'],
    ['Atmosphere/IMG_1428.jpeg', 'mountain-view',   'Столик на фоне горы'],
    ['Atmosphere/IMG_1420.jpeg', 'gazebo-inside',   'Беседка с вуалями (внутри)'],
    ['Atmosphere/IMG_1501.jpeg', 'gazebo-outside',  'Беседка снаружи'],
    ['Atmosphere/IMG_1506.jpeg', 'garden-path',     'Дорожка в саду с зонтиком'],
    ['Atmosphere/IMG_1386.jpeg', 'hibiscus',        'Жёлтый гибискус + веранда'],
    ['Atmosphere/IMG_1416.jpeg', 'interior',        'Обеденный зал интерьер'],

    // ── Food ───────────────────────────────────────────────────
    ['Food/IMG_1225.jpeg',       'food-tuna',       'Тунец-татаки'],
    ['Food/IMG_1272.jpeg',       'food-breakfast',  'Завтрак-сет с лососем'],
    ['Food/IMG_1236.jpeg',       'food-stroganoff', 'Бефстроганов с пюре'],
    ['Food/IMG_1343.jpeg',       'food-chicken',    'Курица фрикасе с пюре'],
];

// Две ширины для responsive srcset. Quality 66 — для фото с большим
// количеством мелкой зелени WebP выдаёт визуально неотличимый результат
// от Q78 при 2.5× меньшем весе. Цель: 1400w ≤ 250KB, 700w ≤ 100KB.
const SIZES = [
    { w: 1400, suffix: '-1400', q: 68 },
    { w: 700,  suffix: '-700',  q: 64 },
];

(async () => {
    for (const [src, name, note] of PICKS) {
        const inPath = path.join(SRC, src);
        if (!fs.existsSync(inPath)) {
            console.warn(`SKIP (missing): ${src}`);
            continue;
        }
        const meta = await sharp(inPath).rotate().metadata();
        console.log(`\n${name}  (${meta.width}×${meta.height})  — ${note}`);

        for (const { w, suffix, q } of SIZES) {
            // Скип апскейла — если исходник уже меньше, отдаём как есть.
            const resizeW = Math.min(w, meta.width);
            const out = path.join(DST, `${name}${suffix}.webp`);
            await sharp(inPath)
                .rotate() // honour EXIF
                .resize({ width: resizeW, withoutEnlargement: true })
                .webp({ quality: q, effort: 6 })
                .toFile(out);
            const sz = (fs.statSync(out).size / 1024).toFixed(1);
            console.log(`  ${path.basename(out)}  ${sz} KB`);
        }
    }
    console.log('\n✓ Done.');
})().catch(e => { console.error(e); process.exit(1); });
