<div class="wrap">
    <div class="top">
        <div class="controls">
            <div class="card filters-card">
                <div class="filters-row">
                    <span class="muted">С</span>
                    <input type="date" id="dateFrom" value="<?= htmlspecialchars($defaultFrom) ?>">
                    <span class="muted">По</span>
                    <input type="date" id="dateTo" value="<?= htmlspecialchars($defaultTo) ?>">
                    <button class="btn" id="loadBtn">Загрузить</button>
                    <label class="chart-switch">
                        <span>Колонки</span>
                        <input type="checkbox" id="chartTypeToggle">
                        <span class="track"><span class="knob"></span></span>
                        <span>Линия</span>
                    </label>
                    <label class="chart-switch">
                        <span>Чеки</span>
                        <input type="checkbox" id="metricToggle">
                        <span class="track"><span class="knob"></span></span>
                        <span>Блюда</span>
                    </label>
                </div>
                <div class="prog" id="prog">
                    <div class="progbar"><div id="progFill"></div></div>
                    <div class="progPct" id="progPct">0%</div>
                    <div class="progText" id="progText">—</div>
                </div>
            </div>
        </div>
    </div>
    <div style="margin-top: 12px;">
        <h1>Zapara</h1>
        <div class="muted">
            Источник: Poster (dash.getTransactions), группировка по дню недели и часу открытия чека
            <span id="zapLegend" style="margin-left: 14px;"></span>
        </div>
    </div>

    <div class="grid" id="charts"><div class="card muted" style="display:flex; align-items:center; justify-content:center; min-height: 120px;">Выбери период и нажми «Загрузить»</div></div>
</div>

<?php
// Auto-bust the JS cache on every deploy — filemtime echo'd into the
// query string means a fresh ?v= every code update, no manual bumps.
$_zaparaJs = __DIR__ . '/../../assets/js/zapara.js';
$_zaparaV  = @filemtime($_zaparaJs) ?: '1';
?>
<script src="/assets/js/zapara.js?v=<?= htmlspecialchars((string)$_zaparaV) ?>"></script>
