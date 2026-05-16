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
<div class="container pd3-page" id="pd3Root">

    <?php require __DIR__ . '/partials/toolbar.php'; ?>

    <!-- IN mode: sepay (incoming bank) ↔ Poster checks. -->
    <section class="pd3-card pd3-graph-card pd3-section pd3-section--in">
        <!-- Single horizontal scroll for the whole tables area.
             Tables never wrap onto separate rows; if the columns don't fit,
             the user scrolls this container left/right. The SVG line layer
             lives inside .pd3-graph__grid so it scrolls with the tables. -->
        <div class="pd3-graph" id="pd3GraphRoot">
            <div class="pd3-graph__grid">
                <?php require __DIR__ . '/partials/sepay_table.php'; ?>
                <?php require __DIR__ . '/partials/mid_col.php'; ?>
                <?php require __DIR__ . '/partials/poster_table.php'; ?>
                <div class="pd3-graph__lines" id="pd3LineLayer" aria-hidden="true"></div>
            </div>
        </div>
    </section>

    <!-- OUT mode placeholder. Full OUT graph (outgoing bank mail ↔
         Poster finance transactions) is Phase 6. The tab still works
         — the UI just shows a placeholder until then. -->
    <section class="pd3-card pd3-graph-card pd3-section pd3-section--out">
        <div class="pd3-out-placeholder">
            <h2>OUT-режим</h2>
            <p class="muted">Сверка исходящих платежей (банковская почта BIDV ↔ финансовые транзакции Poster) ещё не перенесена в payday3.</p>
            <p class="muted">Пока пользуйся <a href="/payday2?<?= http_build_query(['dateFrom' => $range->from, 'dateTo' => $range->to]) ?>" class="pd3-link">payday2</a> для OUT-режима.</p>
        </div>
    </section>

    <?php require __DIR__ . '/partials/totals.php'; ?>
</div>

<script type="application/json" id="pd3-bootstrap">
<?= json_encode([
    'range'     => $range->asArray(),
    'links'     => $linksJson,
    'csrf'      => $_SESSION['payday2_csrf'] ?? '',
    'endpoints' => [
        'links' => '/payday3/api/links',
    ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
</script>
<?php
    $jsMtime = @filemtime(__DIR__ . '/../../../payday3/assets/js/index.js');
    $jsVer = $jsMtime !== false ? (string)$jsMtime : '1';
?>
<script type="module" src="/payday3/assets/js/index.js?v=<?= htmlspecialchars($jsVer) ?>"></script>
