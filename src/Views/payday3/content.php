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

    <!-- OUT mode: BIDV outgoing mail ↔ Poster finance transactions.
         Mail rows are fetched live from IMAP and finance rows from
         Poster API on first activation. The same LineRenderer class
         draws the connectors — only the anchor-id factories differ. -->
    <section class="pd3-card pd3-graph-card pd3-section pd3-section--out">
        <div class="pd3-graph" id="pd3OutGraphRoot">
            <div class="pd3-graph__grid">
                <?php require __DIR__ . '/partials/out_mail_table.php'; ?>
                <?php require __DIR__ . '/partials/out_mid_col.php'; ?>
                <?php require __DIR__ . '/partials/out_finance_table.php'; ?>
                <div class="pd3-graph__lines" id="pd3OutLineLayer" aria-hidden="true"></div>
            </div>
        </div>
    </section>

    <?php require __DIR__ . '/partials/totals.php'; ?>
    <?php require __DIR__ . '/partials/modals.php'; ?>
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
