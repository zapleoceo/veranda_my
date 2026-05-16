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

    <section class="pd3-card pd3-graph-card">
        <div class="pd3-graph" id="pd3GraphRoot">
            <!-- SVG line layer is positioned absolutely over the whole graph.
                 LineRenderer (Phase 3) mounts the SVG node here. -->
            <div class="pd3-graph__lines" id="pd3LineLayer" aria-hidden="true"></div>

            <div class="pd3-graph__grid">
                <?php require __DIR__ . '/partials/sepay_table.php'; ?>
                <?php require __DIR__ . '/partials/mid_col.php'; ?>
                <?php require __DIR__ . '/partials/poster_table.php'; ?>
            </div>
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
