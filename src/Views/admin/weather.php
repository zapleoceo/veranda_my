<?php
/**
 * Выручка vs погода Нячанга.
 * Переменные из WeatherController::index():
 *   $daysJson  — JSON-массив [{date, revenue, checks}, ...]
 *   $dateMin   — первый день в данных
 *   $dateMax   — последний день в данных
 */
declare(strict_types=1);
// phpcs:disable
?>
<style>
/* ── scope: только эта страница, класс-prefix w__ ── */
.w__page       { max-width:1100px; margin:0 auto; padding:0 4px 40px; }
.w__header     { margin-bottom:24px; }
.w__header h2  { font-size:20px; font-weight:700; color:#fff; margin-bottom:4px; }
.w__header p   { font-size:13px; color:#64748b; }
.w__grid       { display:grid; gap:14px; }
.w__card       {
    background:#131e33; border:1px solid #1e2f4a;
    border-radius:12px; padding:20px;
}
.w__card-title { font-size:13px; font-weight:600; color:#e2e8f0; margin-bottom:14px; }
.w__card-sub   { font-size:11px; color:#64748b; margin-top:8px; }
.w__kpis       { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
@media(max-width:620px){ .w__kpis{ grid-template-columns:repeat(2,1fr); } }
.w__kpi        { background:#0b1120; border:1px solid #1e2f4a; border-radius:8px; padding:12px 14px; }
.w__kpi-label  { font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:.05em; margin-bottom:4px; }
.w__kpi-val    { font-size:20px; font-weight:700; color:#fff; }
.w__kpi-sub    { font-size:11px; color:#94a3b8; margin-top:2px; }
.w__badge      { display:inline-block; font-size:10px; font-weight:600; padding:2px 7px; border-radius:4px; margin-top:6px; }
.w__badge-r    { background:rgba(239,68,68,.15);   color:#ef4444; }
.w__badge-g    { background:rgba(34,197,94,.15);   color:#22c55e; }
.w__badge-y    { background:rgba(232,168,56,.15);  color:#e8a838; }
.w__badge-b    { background:rgba(59,130,246,.15);  color:#3b82f6; }
.w__note       {
    font-size:11px; color:#64748b; padding:10px 14px;
    background:#0b1120; border-radius:8px; border:1px solid #1e2f4a;
}
.w__loading    { font-size:12px; color:#64748b; text-align:center; padding:20px; }
.w__corr-row   { display:flex; align-items:center; gap:12px; margin-top:12px; flex-wrap:wrap; }
.w__corr-item  { font-size:12px; color:#e2e8f0; }
.w__corr-val   { font-weight:700; font-size:16px; }
.w__table-wrap { overflow-x:auto; margin-top:12px; }
.w__table      { width:100%; border-collapse:collapse; font-size:12px; }
.w__table th   { text-align:left; padding:6px 10px; font-size:10px; font-weight:600;
                 text-transform:uppercase; letter-spacing:.06em; color:#64748b;
                 border-bottom:1px solid #1e2f4a; }
.w__table td   { padding:6px 10px; border-bottom:1px solid #1e2f4a; color:#94a3b8; }
.w__table tr:hover td { background:rgba(255,255,255,.03); }
.w__table .hi  { color:#ef4444; }
.w__table .num { text-align:right; font-variant-numeric:tabular-nums; }
</style>

<div class="w__page">
  <div class="w__header">
    <h2>🌧 Выручка и погода — Нячанг</h2>
    <p>Данные выручки из poster_checks (центы → VND) · Осадки и ветер из Open-Meteo (archive-api, бесплатно без ключа) · координаты 12.2388° N, 109.1967° E</p>
  </div>

  <!-- KPI-плашки -->
  <div class="w__kpis" id="wKpis">
    <div class="w__kpi">
      <div class="w__kpi-label">Дней данных</div>
      <div class="w__kpi-val" id="wDaysCount">—</div>
      <div class="w__kpi-sub" id="wDaysRange">загрузка…</div>
    </div>
    <div class="w__kpi">
      <div class="w__kpi-label">Корреляция: осадки ↔ выручка</div>
      <div class="w__kpi-val" id="wCorrPrecip">—</div>
      <div class="w__kpi-sub">Пирсон r</div>
    </div>
    <div class="w__kpi">
      <div class="w__kpi-label">Корреляция: ветер ↔ выручка</div>
      <div class="w__kpi-val" id="wCorrWind">—</div>
      <div class="w__kpi-sub">Пирсон r</div>
    </div>
    <div class="w__kpi">
      <div class="w__kpi-label">Худшие дни по выручке</div>
      <div class="w__kpi-val" id="wWorstRev">—</div>
      <div class="w__kpi-sub" id="wWorstRevDate">—</div>
    </div>
  </div>

  <!-- Сводный график -->
  <div class="w__card" style="margin-bottom:14px;">
    <div class="w__card-title">Выручка (VND) и осадки (мм) по дням</div>
    <div id="wChartLoading" class="w__loading">⏳ Загружаю данные погоды из Open-Meteo…</div>
    <canvas id="wChartMain" style="display:none;"></canvas>
    <div class="w__card-sub" id="wChartSub"></div>
  </div>

  <!-- Scatter: осадки vs выручка -->
  <div class="w__grid" style="grid-template-columns:1fr 1fr; gap:14px;" id="wScatterGrid">
    <div class="w__card">
      <div class="w__card-title">Scatter: осадки ↔ выручка</div>
      <canvas id="wScatterPrecip" height="200"></canvas>
      <div class="w__card-sub" id="wScatterPrecipSub"></div>
    </div>
    <div class="w__card">
      <div class="w__card-title">Scatter: ветер (м/с) ↔ выручка</div>
      <canvas id="wScatterWind" height="200"></canvas>
      <div class="w__card-sub" id="wScatterWindSub"></div>
    </div>
  </div>

  <!-- Топ плохих дней -->
  <div class="w__card" style="margin-top:14px;">
    <div class="w__card-title">🔴 Топ-15 дней с минимальной выручкой — что было с погодой</div>
    <div id="wWorstTable" class="w__loading">загрузка…</div>
  </div>

  <div class="w__note" style="margin-top:14px;">
    <strong>Как читать:</strong>
    Коэффициент Пирсона r: от −1 до +1. Значение r &lt; −0.25 означает умеренную отрицательную корреляцию (больше дождя → меньше выручки).
    r > −0.15 → погода статистически не влияет на вашу выручку.
    Scatter-диаграммы показывают каждый день как точку.
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(async function () {
    // ── 1. Данные из PHP ──────────────────────────────────────────
    const DAYS = <?= $daysJson ?>;
    const DATE_MIN = <?= json_encode($dateMin) ?>;
    const DATE_MAX = <?= json_encode($dateMax) ?>;

    // ── 2. Погода из Open-Meteo (archive API, бесплатно) ─────────
    let weatherByDate = {};
    try {
        const url = `https://archive-api.open-meteo.com/v1/archive?latitude=12.2388&longitude=109.1967` +
            `&start_date=${DATE_MIN}&end_date=${DATE_MAX}` +
            `&daily=precipitation_sum,wind_speed_10m_max,weather_code` +
            `&timezone=Asia%2FHo_Chi_Minh`;
        const res = await fetch(url);
        const w = await res.json();
        const dates = w.daily?.time || [];
        const precip = w.daily?.precipitation_sum || [];
        const wind   = w.daily?.wind_speed_10m_max || [];
        const wcode  = w.daily?.weather_code || [];
        dates.forEach((d, i) => {
            weatherByDate[d] = {
                precip: precip[i] ?? 0,
                wind:   wind[i]   ?? 0,
                wcode:  wcode[i]  ?? 0,
            };
        });
    } catch (e) {
        document.getElementById('wChartLoading').textContent = '❌ Не удалось загрузить данные погоды: ' + e.message;
    }

    // ── 3. Объединяем ─────────────────────────────────────────────
    const joined = DAYS.map(d => ({
        ...d,
        ...(weatherByDate[d.date] || { precip: null, wind: null, wcode: null }),
    })).filter(d => d.precip !== null);

    if (!joined.length) {
        document.getElementById('wChartLoading').textContent = '⚠ Нет перекрывающихся данных';
        return;
    }

    // ── 4. Корреляция Пирсона ────────────────────────────────────
    function pearson(xs, ys) {
        const n = xs.length;
        if (n < 3) return null;
        const mx = xs.reduce((a, b) => a + b, 0) / n;
        const my = ys.reduce((a, b) => a + b, 0) / n;
        let num = 0, dx2 = 0, dy2 = 0;
        for (let i = 0; i < n; i++) {
            const dx = xs[i] - mx, dy = ys[i] - my;
            num += dx * dy; dx2 += dx * dx; dy2 += dy * dy;
        }
        return (dx2 === 0 || dy2 === 0) ? 0 : num / Math.sqrt(dx2 * dy2);
    }

    const revenues = joined.map(d => d.revenue);
    const precips  = joined.map(d => d.precip);
    const winds    = joined.map(d => d.wind);

    const rPrecip = pearson(precips, revenues);
    const rWind   = pearson(winds, revenues);

    function corrLabel(r) {
        if (r === null) return { text: 'нет данных', cls: 'w__badge-y' };
        const a = Math.abs(r);
        if (a < 0.10) return { text: 'нет связи', cls: 'w__badge-g' };
        if (a < 0.25) return { text: 'слабая', cls: 'w__badge-y' };
        if (a < 0.50) return { text: 'умеренная', cls: 'w__badge-y' };
        return { text: 'сильная', cls: 'w__badge-r' };
    }

    const fmt = n => new Intl.NumberFormat('ru-RU').format(Math.round(n));

    // ── 5. KPI ───────────────────────────────────────────────────
    document.getElementById('wDaysCount').textContent = joined.length;
    document.getElementById('wDaysRange').textContent = DATE_MIN + ' — ' + DATE_MAX;

    const cpEl = document.getElementById('wCorrPrecip');
    const cwEl = document.getElementById('wCorrWind');
    const lpre = corrLabel(rPrecip), lwin = corrLabel(rWind);
    cpEl.innerHTML = `<span class="w__corr-val">${rPrecip !== null ? rPrecip.toFixed(2) : '—'}</span>
        <br><span class="w__badge ${lpre.cls}">${lpre.text}</span>`;
    cwEl.innerHTML = `<span class="w__corr-val">${rWind   !== null ? rWind.toFixed(2) : '—'}</span>
        <br><span class="w__badge ${lwin.cls}">${lwin.text}</span>`;

    const sorted = [...joined].sort((a, b) => a.revenue - b.revenue);
    const worst  = sorted[0];
    document.getElementById('wWorstRev').textContent    = fmt(worst.revenue) + ' ₫';
    document.getElementById('wWorstRevDate').textContent = worst.date + ' · ' + worst.precip + 'мм дождя';

    // ── 6. Главный временной график ──────────────────────────────
    document.getElementById('wChartLoading').style.display = 'none';
    const canvas = document.getElementById('wChartMain');
    canvas.style.display = 'block';

    const labels = joined.map(d => d.date);
    const chartMain = new Chart(canvas, {
        data: {
            labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Выручка (VND)',
                    data: joined.map(d => d.revenue),
                    backgroundColor: joined.map(d =>
                        d.precip > 20 ? 'rgba(239,68,68,0.6)' :
                        d.precip > 8  ? 'rgba(232,168,56,0.5)' :
                                        'rgba(59,130,246,0.45)'
                    ),
                    yAxisID: 'yRev',
                    order: 2,
                },
                {
                    type: 'line',
                    label: 'Осадки (мм)',
                    data: joined.map(d => d.precip),
                    borderColor: 'rgba(148,163,184,0.7)',
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    pointRadius: 0,
                    yAxisID: 'yPrecip',
                    order: 1,
                    tension: 0.2,
                },
            ],
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                yRev: {
                    position: 'left',
                    grid: { color: '#1e2f4a' },
                    ticks: { color: '#64748b', callback: v => (v / 1e6).toFixed(1) + 'M' },
                },
                yPrecip: {
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { color: '#64748b', callback: v => v + 'мм' },
                    min: 0,
                },
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#64748b',
                        maxTicksLimit: 16,
                        callback: (_, i) => labels[i]?.slice(5) ?? '',
                    },
                },
            },
            plugins: {
                legend: { labels: { color: '#94a3b8', font: { size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            if (ctx.datasetIndex === 0) return ` Выручка: ${fmt(ctx.parsed.y)} ₫`;
                            return ` Осадки: ${ctx.parsed.y} мм`;
                        },
                    },
                },
            },
        },
    });

    document.getElementById('wChartSub').textContent =
        `Синий = сухой день · Жёлтый = дождь 8–20мм · Красный = сильный дождь >20мм`;

    // ── 7. Scatter: осадки ──────────────────────────────────────
    new Chart(document.getElementById('wScatterPrecip'), {
        type: 'scatter',
        data: {
            datasets: [{
                data: joined.map(d => ({ x: d.precip, y: d.revenue })),
                backgroundColor: 'rgba(59,130,246,0.4)',
                pointRadius: 4,
                label: 'день',
            }],
        },
        options: {
            scales: {
                x: { title: { display: true, text: 'Осадки, мм', color: '#64748b' }, grid: { color: '#1e2f4a' }, ticks: { color: '#64748b' } },
                y: { title: { display: true, text: 'Выручка, VND', color: '#64748b' }, grid: { color: '#1e2f4a' },
                     ticks: { color: '#64748b', callback: v => (v / 1e6).toFixed(1) + 'M' } },
            },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: {
                    label: ctx => `${ctx.raw.x}мм → ${fmt(ctx.raw.y)} ₫`,
                }},
            },
        },
    });
    document.getElementById('wScatterPrecipSub').textContent =
        `r = ${rPrecip !== null ? rPrecip.toFixed(3) : '—'} · ${lpre.text}`;

    // ── 8. Scatter: ветер ────────────────────────────────────────
    new Chart(document.getElementById('wScatterWind'), {
        type: 'scatter',
        data: {
            datasets: [{
                data: joined.map(d => ({ x: d.wind, y: d.revenue })),
                backgroundColor: 'rgba(232,168,56,0.4)',
                pointRadius: 4,
                label: 'день',
            }],
        },
        options: {
            scales: {
                x: { title: { display: true, text: 'Макс. ветер, м/с', color: '#64748b' }, grid: { color: '#1e2f4a' }, ticks: { color: '#64748b' } },
                y: { title: { display: true, text: 'Выручка, VND', color: '#64748b' }, grid: { color: '#1e2f4a' },
                     ticks: { color: '#64748b', callback: v => (v / 1e6).toFixed(1) + 'M' } },
            },
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: {
                    label: ctx => `${ctx.raw.x}м/с → ${fmt(ctx.raw.y)} ₫`,
                }},
            },
        },
    });
    document.getElementById('wScatterWindSub').textContent =
        `r = ${rWind !== null ? rWind.toFixed(3) : '—'} · ${lwin.text}`;

    // ── 9. Топ-15 худших дней ────────────────────────────────────
    const worst15 = sorted.slice(0, 15);
    const wmoDesc = code => {
        if (code === null) return '—';
        if (code === 0)  return '☀ Ясно';
        if (code <= 3)   return '⛅ Облачно';
        if (code <= 49)  return '🌫 Туман/морось';
        if (code <= 69)  return '🌧 Дождь';
        if (code <= 79)  return '🌨 Снег/крупа';
        if (code <= 99)  return '⛈ Гроза';
        return String(code);
    };

    const avgRev = revenues.reduce((a, b) => a + b, 0) / revenues.length;

    const rows = worst15.map(d => {
        const pct = d.revenue / avgRev * 100;
        return `<tr>
            <td>${d.date}</td>
            <td class="num ${d.revenue < avgRev * 0.4 ? 'hi' : ''}">${fmt(d.revenue)}</td>
            <td class="num">${Math.round(pct)}%</td>
            <td class="num ${d.precip > 10 ? 'hi' : ''}">${d.precip}</td>
            <td class="num ${d.wind > 10 ? 'hi' : ''}">${d.wind ?? '—'}</td>
            <td>${wmoDesc(d.wcode)}</td>
        </tr>`;
    }).join('');

    document.getElementById('wWorstTable').innerHTML = `
        <div class="w__table-wrap">
        <table class="w__table">
            <thead><tr>
                <th>Дата</th>
                <th class="num">Выручка, VND</th>
                <th class="num">% от среднего</th>
                <th class="num">Осадки, мм</th>
                <th class="num">Ветер, м/с</th>
                <th>Погода WMO</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>
        </div>
        <div class="w__card-sub">Средняя дневная выручка за период: ${fmt(avgRev)} ₫ · Красным = хуже 40% от среднего</div>`;
})();
</script>
