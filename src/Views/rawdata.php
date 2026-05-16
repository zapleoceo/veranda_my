<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/links/favicon.svg">
    <title>Raw Data - Kitchen Analytics</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/datepicker-range-dialog.css">
    <link rel="stylesheet" href="/assets/css/common.css?v=20260516_tokens">
    <link rel="stylesheet" href="/assets/css/user_menu.css">
    <link rel="stylesheet" href="/assets/css/rawdata.css">
</head>
<body>
<div class="container">
    <div class="top-nav">
        <div class="nav-left"><div class="nav-title">Таблица</div></div>
        <div class="nav-right">
            <?php require __DIR__ . '/partials/user_menu.php'; ?>
        </div>
    </div>

    <div class="last-sync">
        <span>Последнее обновление из Poster: <?= htmlspecialchars($lastSyncLabel) ?></span>
        <label class="resync-toggle">
            <input type="checkbox" name="resync" value="1" form="rawdataFilters"> Resync
        </label>
    </div>

    <form class="filter-section" method="GET" id="rawdataFilters" action="/rawdata">
        <div class="filter-group">
            <label>Период</label>
            <div class="dp-range" data-date-range-picker data-from-input="dateFromInput" data-to-input="dateToInput">
                <div class="dp-field">
                    <input type="text" id="dateRangeBtn" class="dp-display range-btn" readonly>
                </div>
                <input type="hidden" name="dateFrom" id="dateFromInput" value="<?= htmlspecialchars($dateFrom) ?>">
                <input type="hidden" name="dateTo" id="dateToInput" value="<?= htmlspecialchars($dateTo) ?>">
                <div class="dp-overlay" data-dp-overlay hidden></div>
                <div class="dp-dialog" data-dp-dialog role="dialog" aria-modal="true" aria-label="Выбор периода" hidden>
                    <div class="dp-header">
                        <button type="button" class="dp-nav dp-prev-month" aria-label="Предыдущий месяц">‹</button>
                        <div class="dp-month-year" aria-live="polite"></div>
                        <button type="button" class="dp-nav dp-next-month" aria-label="Следующий месяц">›</button>
                    </div>
                    <table class="dp-grid" role="grid" aria-label="Календарь">
                        <thead><tr></tr></thead>
                        <tbody></tbody>
                    </table>
                    <div class="dp-footer">
                        <div class="dp-hint" aria-live="polite"></div>
                        <div class="dp-actions">
                            <button type="button" class="dp-action dp-cancel" value="cancel">Отмена</button>
                            <button type="button" class="dp-action primary dp-ok" value="ok">OK</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="filter-group">
            <label>Время</label>
            <div style="display:flex;gap:10px">
                <select name="hourStart">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <option value="<?= $h ?>" <?= $hourStart == $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                    <?php endfor; ?>
                </select>
                <select name="hourEnd">
                    <?php for ($h = 1; $h <= 24; $h++): ?>
                        <option value="<?= $h ?>" <?= $hourEnd == $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <div class="filter-group">
            <label for="station">Цех:</label>
            <select name="station" id="station">
                <option value="all" <?= $stationFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <option value="2" <?= $stationFilter === '2' ? 'selected' : '' ?>>Kitchen (2)</option>
                <option value="3" <?= $stationFilter === '3' ? 'selected' : '' ?>>Bar (3)</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="status">Статус:</label>
            <select name="status" id="status">
                <option value="all" <?= $selectedStatus === 'all' ? 'selected' : '' ?>>Все чеки</option>
                <option value="open" <?= $selectedStatus === 'open' ? 'selected' : '' ?>>Только открытые</option>
                <option value="closed" <?= $selectedStatus === 'closed' ? 'selected' : '' ?>>Только закрытые</option>
            </select>
        </div>

        <div class="spacer"></div>
        <button type="submit">Применить</button>
        <?php if ($selectedStatus !== 'all' || $dateFrom !== date('Y-m-d') || $dateTo !== date('Y-m-d') || $hourStart !== 0 || $hourEnd !== 23 || $stationFilter !== 'all'): ?>
            <a href="/rawdata" style="font-size:.9em;color:var(--muted);margin-left:10px">Сбросить</a>
        <?php endif; ?>
    </form>

    <div class="table-header-sticky" id="mainTableHeader">
        <div data-sort="receipt" class="sort-asc">Чек <span class="sort-icon"></span></div>
        <div data-sort="opened">ВрОткр <span class="sort-icon"></span></div>
        <div data-sort="closed">ВрЛогЗакр <span class="sort-icon"></span></div>
        <div data-sort="wait">Макс. ожидание <span class="sort-icon"></span></div>
    </div>

    <div id="lazyStatus" style="text-align:center;color:var(--muted);height:0;margin:0;overflow:hidden"></div>
    <div id="receiptsList"></div>
    <div id="lazySentinel" style="height:1px"></div>
</div>

<script src="/assets/app.js" defer></script>
<script src="/assets/user_menu.js" defer></script>
<script src="/assets/datepicker-range-dialog.js"></script>
<script src="/assets/js/rawdata.js"></script>
</body>
</html>
