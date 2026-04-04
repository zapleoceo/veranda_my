<!doctype html>
<html lang="ru" data-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Restaurant Table Map</title>
  <link rel="preconnect" href="https://api.fontshare.com">
  <link rel="preconnect" href="https://cdn.fontshare.com" crossorigin>
  <link href="https://api.fontshare.com/v2/css?f[]=satoshi@400,500,700&f[]=clash-display@500,600&display=swap" rel="stylesheet">
  <style>
    :root {
      --text-xs: clamp(0.75rem, 0.7rem + 0.25vw, 0.875rem);
      --text-sm: clamp(0.875rem, 0.8rem + 0.35vw, 1rem);
      --text-base: clamp(1rem, 0.95rem + 0.25vw, 1.125rem);
      --text-lg: clamp(1.125rem, 1rem + 0.75vw, 1.5rem);
      --text-xl: clamp(1.5rem, 1.2rem + 1.25vw, 2.25rem);
      --space-1: 0.25rem; --space-2: 0.5rem; --space-3: 0.75rem; --space-4: 1rem; --space-5: 1.25rem; --space-6: 1.5rem; --space-8: 2rem; --space-10: 2.5rem; --space-12: 3rem;
      --color-bg: #f5f2ea;
      --color-surface: #fcfbf7;
      --color-surface-2: #f0ece3;
      --color-border: rgba(43, 36, 28, 0.12);
      --color-text: #2b241c;
      --color-text-muted: #746a60;
      --color-primary: #7b4b2a;
      --color-primary-strong: #5f3417;
      --color-accent: #c89a63;
      --color-success: #4f7b4b;
      --shadow-sm: 0 8px 20px rgba(43, 36, 28, 0.08);
      --shadow-lg: 0 20px 60px rgba(43, 36, 28, 0.14);
      --radius-md: 16px;
      --radius-lg: 24px;
      --radius-full: 999px;
      --font-body: 'Satoshi', sans-serif;
      --font-display: 'Clash Display', 'Satoshi', sans-serif;
    }
  
    [data-theme="dark"] {
      --color-bg: #181513;
      --color-surface: #23201c;
      --color-surface-2: #2b2722;
      --color-border: rgba(255, 245, 232, 0.1);
      --color-text: #f5eee4;
      --color-text-muted: #b7ab9d;
      --color-primary: #d59c5a;
      --color-primary-strong: #f0bd7d;
      --color-accent: #7b4b2a;
      --shadow-sm: 0 8px 20px rgba(0, 0, 0, 0.22);
      --shadow-lg: 0 20px 60px rgba(0, 0, 0, 0.34);
    }
  
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body {
      font-family: var(--font-body);
      background:
        radial-gradient(circle at top left, rgba(200,154,99,.12), transparent 28%),
        radial-gradient(circle at right bottom, rgba(123,75,42,.08), transparent 24%),
        var(--color-bg);
      color: var(--color-text);
    }
  
    .app {
      min-height: 100vh;
      padding: var(--space-6);
      display: grid;
      place-items: center;
    }
  
    .panel {
      width: min(1200px, 100%);
      background: linear-gradient(180deg, rgba(255,255,255,.35), transparent), var(--color-surface);
      border: 1px solid var(--color-border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lg);
      overflow: hidden;
    }
  
    .topbar {
      display: flex;
      justify-content: space-between;
      gap: var(--space-4);
      align-items: center;
      padding: var(--space-5) var(--space-6);
      border-bottom: 1px solid var(--color-border);
      background: rgba(255,255,255,0.24);
      backdrop-filter: blur(14px);
    }
  
    .title-wrap h1 {
      margin: 0;
      font-family: var(--font-display);
      font-size: var(--text-xl);
      line-height: 1;
      letter-spacing: -0.03em;
    }
  
    .title-wrap p {
      margin: var(--space-2) 0 0;
      color: var(--color-text-muted);
      font-size: var(--text-sm);
    }
  
    .controls {
      display: flex;
      gap: var(--space-3);
      align-items: center;
      flex-wrap: wrap;
    }
  
    .legend, .theme-toggle {
      border: 1px solid var(--color-border);
      background: var(--color-surface-2);
      border-radius: var(--radius-full);
      padding: var(--space-2) var(--space-4);
      font-size: var(--text-sm);
      color: var(--color-text);
    }
  
    .theme-toggle { cursor: pointer; }
  
    .layout {
      padding: var(--space-6);
      display: grid;
      grid-template-columns: 1fr 280px;
      gap: var(--space-6);
    }
  
    .map-shell {
      background:
        linear-gradient(var(--color-border) 1px, transparent 1px),
        linear-gradient(90deg, var(--color-border) 1px, transparent 1px),
        var(--color-surface-2);
      background-size: 28px 28px;
      border-radius: calc(var(--radius-lg) - 8px);
      padding: var(--space-6);
      border: 1px solid var(--color-border);
      overflow: auto;
    }
  
    .map {
      position: relative;
      min-width: 820px;
      min-height: 620px;
      border-radius: var(--radius-md);
    }

    .grass-area {
      position: absolute;
      left: -22px;
      top: -28px;
      width: 860px;
      height: 240px;
      border-radius: 44px;
      background:
        radial-gradient(circle at 12% 24%, rgba(147,196,125,0.20), transparent 58%),
        radial-gradient(circle at 78% 30%, rgba(92,162,92,0.22), transparent 62%),
        radial-gradient(circle at 44% 78%, rgba(200,154,99,0.10), transparent 60%),
        repeating-linear-gradient(115deg, rgba(34,88,34,0.22) 0 2px, rgba(52,116,52,0.18) 2px 5px),
        repeating-linear-gradient(25deg, rgba(72,148,72,0.16) 0 3px, rgba(28,84,28,0.14) 3px 7px),
        linear-gradient(180deg, rgba(255,255,255,0.06), rgba(0,0,0,0.10)),
        rgba(52,116,52,0.12);
      background-blend-mode: screen, screen, normal, overlay, overlay, normal, normal;
      border: 1px solid rgba(255,255,255,0.12);
      box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), inset 0 -18px 30px rgba(0,0,0,0.20);
      pointer-events: none;
      opacity: 0.98;
      filter: saturate(1.35) contrast(1.05);
    }
  
    .bar-row {
      position: absolute;
      left: 50%;
      bottom: 28px;
      transform: translateX(-50%);
      display: flex;
      align-items: center;
      gap: 56px;
      user-select: none;
    }

    .bar {
      width: 260px;
      height: 72px;
      border-radius: 36px;
      background: linear-gradient(180deg, var(--color-primary), var(--color-primary-strong));
      color: #fff8ef;
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-size: 1.9rem;
      letter-spacing: 0.08em;
      box-shadow: var(--shadow-sm);
    }

    .side-station {
      width: 170px;
      height: 58px;
      border-radius: 18px;
      border: 1px solid rgba(255,255,255,.16);
      background: linear-gradient(180deg, rgba(255,255,255,0.10), rgba(0,0,0,0.10)), rgba(255,255,255,0.04);
      box-shadow: 0 12px 20px rgba(0,0,0,0.22);
      color: rgba(245,238,228,0.92);
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-size: 1.05rem;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
  
    .table {
      position: absolute;
      width: 74px;
      height: 74px;
      border: 1px solid rgba(255,255,255,.24);
      border-radius: 22px;
      background: linear-gradient(180deg, #b58a63, #8b5e3b);
      color: #fffaf4;
      box-shadow: 0 14px 24px rgba(84, 49, 20, .22);
      display: grid;
      place-items: center;
      font-family: var(--font-display);
      font-size: 1.45rem;
      font-weight: 600;
      letter-spacing: -0.03em;
      cursor: pointer;
      transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
      user-select: none;
    }
  
    .table::after {
      content: '';
      position: absolute;
      inset: auto 10px 10px auto;
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background: rgba(255,255,255,.35);
      opacity: .6;
    }
  
    .table:hover, .table:focus-visible {
      transform: translateY(-3px) scale(1.02);
      box-shadow: 0 18px 34px rgba(84, 49, 20, .3);
      filter: saturate(1.05);
      outline: none;
    }
  
    .table.selected {
      background: linear-gradient(180deg, #4f7b4b, #355b33);
      box-shadow: 0 18px 34px rgba(43, 89, 50, .28);
    }
  
    .table.small-vertical { width: 58px; height: 92px; border-radius: 18px; }
    .table.wide { width: 112px; height: 58px; border-radius: 18px; }
    .table.large { width: 108px; height: 108px; border-radius: 26px; }
  
    .sidebar {
      display: grid;
      gap: var(--space-4);
      align-content: start;
    }
  
    .card {
      background: var(--color-surface-2);
      border: 1px solid var(--color-border);
      border-radius: calc(var(--radius-lg) - 8px);
      padding: var(--space-5);
      box-shadow: var(--shadow-sm);
    }
  
    .card h2 {
      margin: 0 0 var(--space-3);
      font-size: var(--text-lg);
      font-family: var(--font-display);
      line-height: 1.1;
    }
  
    .card p, .card li {
      color: var(--color-text-muted);
      font-size: var(--text-sm);
      margin: 0;
    }
  
    .selected-output {
      margin-top: var(--space-4);
      padding: var(--space-4);
      border-radius: var(--radius-md);
      background: rgba(123,75,42,.08);
      color: var(--color-text);
      min-height: 74px;
    }
  
    .selected-list {
      display: flex;
      flex-wrap: wrap;
      gap: var(--space-2);
      margin-top: var(--space-3);
    }
  
    .pill {
      background: rgba(123,75,42,.12);
      color: var(--color-primary-strong);
      padding: 0.45rem 0.8rem;
      border-radius: var(--radius-full);
      font-size: var(--text-sm);
      font-weight: 700;
    }
  
    .actions {
      display: flex;
      gap: var(--space-3);
      flex-wrap: wrap;
      margin-top: var(--space-4);
    }
  
    .btn {
      border: 0;
      border-radius: var(--radius-full);
      padding: 0.9rem 1rem;
      font-size: var(--text-sm);
      font-weight: 700;
      cursor: pointer;
    }
  
    .btn-primary { background: var(--color-primary); color: #fffaf4; }
    .btn-secondary { background: transparent; color: var(--color-text); border: 1px solid var(--color-border); }
  
    .stats { display: none; }
    .stat { display: none; }
    .footer-note { display: none; }
  
    @media (max-width: 980px) {
      .layout { grid-template-columns: 1fr; }
      .map { min-width: 720px; }
    }
  
    @media (max-width: 640px) {
      .app, .layout, .map-shell { padding: var(--space-4); }
      .topbar { padding: var(--space-4); align-items: flex-start; flex-direction: column; }
      .map { min-width: 640px; min-height: 600px; }
    }
  </style>
</head>
<body>
  <div class="app">
    <main class="panel">
      <div class="topbar">
        <div class="title-wrap">
          <h1>Схема бронирования</h1>
        </div>
        <div class="controls">
          <button class="theme-toggle" type="button" data-theme-toggle aria-label="Переключить тему">☀️</button>
        </div>
      </div>
  
      <section class="layout">
        <div class="map-shell">
          <div class="map" aria-label="Схема столов ресторана">
            <div class="grass-area" aria-hidden="true"></div>
            <button class="table large" style="left: 0px; top: 24px;" data-table="1">1</button>
            <button class="table large" style="left: 0px; top: 150px;" data-table="2">2</button>
            <button class="table large" style="left: 0px; top: 276px;" data-table="3">3</button>
  
            <button class="table" style="left: 200px; top: 56px;" data-table="4">4</button>
            <button class="table" style="left: 308px; top: 56px;" data-table="5">5</button>
            <button class="table" style="left: 512px; top: 56px;" data-table="6">6</button>
            <button class="table large" style="left: 700px; top: 0px;" data-table="7">7</button>
  
            <button class="table wide" style="left: 174px; top: 142px;" data-table="8">8</button>
            <button class="table wide" style="left: 296px; top: 142px;" data-table="9">9</button>
            <button class="table wide" style="left: 522px; top: 142px;" data-table="10">10</button>
            <button class="table wide" style="left: 644px; top: 142px;" data-table="11">11</button>
  
            <button class="table" style="left: 232px; top: 242px;" data-table="12">12</button>
            <button class="table" style="left: 360px; top: 242px;" data-table="13">13</button>
            <button class="table" style="left: 472px; top: 242px;" data-table="14">14</button>
  
            <button class="table small-vertical" style="left: 158px; top: 336px;" data-table="15">15</button>
            <button class="table small-vertical" style="left: 258px; top: 336px;" data-table="16">16</button>
            <button class="table small-vertical" style="left: 358px; top: 336px;" data-table="17">17</button>
            <button class="table small-vertical" style="left: 458px; top: 336px;" data-table="18">18</button>
            <button class="table small-vertical" style="left: 558px; top: 336px;" data-table="19">19</button>
            <button class="table large" style="left: 646px; top: 314px;" data-table="20">20</button>
  
            <div class="bar-row" aria-hidden="true">
              <div class="side-station">Касса</div>
              <div class="bar">BAR</div>
              <div class="side-station">Музыканты</div>
            </div>
          </div>
        </div>
  
        <aside class="sidebar">
          <div class="card">
            <h2>Выбор столов</h2>
            <p>Нажимайте на столы прямо на схеме. Сейчас можно выбирать несколько столов - удобно для будущей логики бронирования, фильтров или статусов.</p>
            <div class="selected-output">
              <div>Выбрано столов: <strong id="selectedCount">0</strong></div>
              <div class="selected-list" id="selectedList"><span style="color:var(--color-text-muted);font-size:var(--text-sm);">Пока ничего не выбрано</span></div>
            </div>
            <div class="actions">
              <button class="btn btn-primary" id="bookBtn" type="button">Забронировать</button>
              <button class="btn btn-secondary" id="clearBtn" type="button">Очистить</button>
            </div>
          </div>
  
  
        </aside>
      </section>
    </main>
  </div>
  
  <script>
    const root = document.documentElement;
    const toggle = document.querySelector('[data-theme-toggle]');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    root.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
    toggle.textContent = prefersDark ? '☀️' : '🌙';
  
    toggle.addEventListener('click', () => {
      const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      toggle.textContent = next === 'dark' ? '☀️' : '🌙';
    });
  
    const selected = new Set();
    const countEl = document.getElementById('selectedCount');
    const listEl = document.getElementById('selectedList');
    const tables = Array.from(document.querySelectorAll('.table'));
  
    function renderSelected() {
      const arr = Array.from(selected).sort((a, b) => Number(a) - Number(b));
      countEl.textContent = arr.length;
      listEl.innerHTML = arr.length
        ? arr.map(n => `<span class="pill">Стол ${n}</span>`).join('')
        : '<span style="color:var(--color-text-muted);font-size:var(--text-sm);">Пока ничего не выбрано</span>';
    }
  
    tables.forEach(table => {
      table.addEventListener('click', () => {
        const id = table.dataset.table;
        if (selected.has(id)) {
          selected.delete(id);
          table.classList.remove('selected');
        } else {
          selected.add(id);
          table.classList.add('selected');
        }
        renderSelected();
      });
    });
  
    document.getElementById('clearBtn').addEventListener('click', () => {
      selected.clear();
      tables.forEach(t => t.classList.remove('selected'));
      renderSelected();
    });
  
    document.getElementById('bookBtn').addEventListener('click', () => {
      const arr = Array.from(selected).sort((a, b) => Number(a) - Number(b));
      if (!arr.length) {
        alert('Сначала выберите хотя бы один стол.');
        return;
      }
      alert(`Здесь можно подвязать реальную бронь. Сейчас выбрано: ${arr.join(', ')}`);
    });
  
    renderSelected();
  </script>
</body>
</html>
