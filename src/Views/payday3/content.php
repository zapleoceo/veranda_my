<?php
/** @var \App\Payday3\Domain\DateRange                $range */
/** @var \App\Payday3\Domain\SepayTransaction[]       $sepayOpen */
/** @var \App\Payday3\Domain\SepayTransaction[]       $sepayHidden */
/** @var \App\Payday3\Domain\PosterTransaction[]      $poster */
/** @var \App\Payday3\Domain\ReconciliationLink[]     $links */
/** @var array<int,array>                             $linksJson */
/** @var array<int,list<\App\Payday3\Domain\ReconciliationLink>> $linkBySepay */
/** @var array<int,list<\App\Payday3\Domain\ReconciliationLink>> $linkByPoster */
/** @var array<int,string>                            $rowStateBySepay */
/** @var array<int,string>                            $rowStateByPoster */

declare(strict_types=1);
?>
<!-- Apply persisted font-scale class to <html> BEFORE the page paints
     so we don't flash the default size and then jump. The matching CSS
     rule is `html.pd3-scale-1-2 .pd3-page { zoom: 1.2 }` etc. -->
<script>(function () {
    try {
        var s = localStorage.getItem('pd3.fontScale');
        if (s === '1.2' || s === '1.5') {
            document.documentElement.classList.add('pd3-scale-' + s.replace('.', '-'));
        }
    } catch (_) { /* private mode — ignore */ }
})();</script>
<div class="container pd3-page" id="pd3Root">

    <?php require __DIR__ . '/partials/toolbar.php'; ?>

    <!-- One reconciliation graph for the whole day:
           left   «Деньги» — incoming (SePay) above, outgoing (BIDV mail)
                  below, one table;
           middle one link panel for every pair;
           right  Poster checks above Poster finance transactions.
         Income with income, expense with expense — three pairs, three
         LineRenderer instances on the same grid, each with its own SVG
         layer: incoming↔checks, outgoing↔finance, incoming↔finance
         income. The whole grid
         scrolls horizontally when the columns don't fit; the layers live
         inside it so the connectors scroll with the tables. -->
    <section class="pd3-card pd3-graph-card">
        <div class="pd3-graph" id="pd3GraphRoot">
            <div class="pd3-graph__grid">
                <?php require __DIR__ . '/partials/bank_table.php'; ?>
                <?php require __DIR__ . '/partials/mid_col.php'; ?>
                <div class="pd3-right" id="pd3RightColumn">
                    <?php require __DIR__ . '/partials/poster_table.php'; ?>
                    <?php require __DIR__ . '/partials/out_finance_table.php'; ?>
                </div>
                <div class="pd3-graph__lines" id="pd3LineLayer" aria-hidden="true"></div>
                <div class="pd3-graph__lines" id="pd3OutLineLayer" aria-hidden="true"></div>
                <div class="pd3-graph__lines" id="pd3IncomeLineLayer" aria-hidden="true"></div>
            </div>
        </div>
    </section>

    <?php require __DIR__ . '/partials/totals.php'; ?>
    <!-- Bottom row: Итоговый баланс + Финансовые транзакции side by side.
         Flex wraps to a stack on narrow viewports; each card auto-sizes
         to its content (no wasted whitespace). -->
    <section class="pd3-bottom-row">
        <?php require __DIR__ . '/partials/finance_transfers.php'; ?>
        <?php require __DIR__ . '/partials/balances.php'; ?>
    </section>
    <?php require __DIR__ . '/partials/modals.php'; ?>
</div>

<script type="application/json" id="pd3-bootstrap">
<?= json_encode([
    'range'     => $range->asArray(),
    'links'     => $linksJson,
    // Per-session token for CsrfGuard on /payday3/api (Payday3Controller).
    'csrf'      => (string)($csrfToken ?? ''),
    // Surfaced for create-transaction modal's default comment
    // ("Created by <email>") — same shape as payday2's PAYDAY_CONFIG.
    'userEmail' => (string)($_SESSION['user_email'] ?? $_SESSION['user_name'] ?? ''),
    'endpoints' => [
        'links' => '/payday3/api/links',
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>
<?php
    $jsMtime = @filemtime(__DIR__ . '/../../../payday3/assets/js/index.js');
    $jsVer = $jsMtime !== false ? (string)$jsMtime : '1';
?>
<?php /* Must precede every module script: versions each module's URL
         (static imports carry no ?v=) — see ModuleImportMap. */ ?>
<script type="importmap"><?= \App\Payday3\Http\ModuleImportMap::json() ?></script>
<script type="module" src="/payday3/assets/js/index.js?v=<?= htmlspecialchars($jsVer) ?>"></script>
