<?php
/** @var \App\Payday3\Domain\DateRange                $range */
/** @var \App\Payday3\Domain\SepayTransaction[]       $sepayOpen */
/** @var \App\Payday3\Domain\SepayTransaction[]       $sepayHidden */
/** @var \App\Payday3\Domain\PosterTransaction[]      $poster */
/** @var array<int,array>                             $linksJson */

declare(strict_types=1);
?>
<div class="container pd3-page">
    <div class="top-nav">
        <div class="nav-left">
            <span class="nav-title">PayDay3</span>
            <span class="muted">
                <?= htmlspecialchars($range->from) ?>
                <?= $range->isSingleDay() ? '' : ' — ' . htmlspecialchars($range->to) ?>
            </span>
        </div>
        <?php require dirname(__DIR__) . '/partials/user_menu.php'; ?>
    </div>

    <?php require __DIR__ . '/partials/filters.php'; ?>

    <section class="pd3-graph" id="pd3GraphRoot">
        <div class="pd3-graph__tables" id="pd3TablesRoot">
            <?php require __DIR__ . '/partials/sepay_table.php'; ?>
            <?php require __DIR__ . '/partials/poster_table.php'; ?>
        </div>
        <!-- SVG line-renderer mounts inside this element. Empty on the
             server; payday3.js owns its lifecycle. -->
        <div class="pd3-graph__lines" id="pd3LineLayer" aria-hidden="true"></div>
    </section>

    <?php require __DIR__ . '/partials/totals.php'; ?>
</div>

<script type="application/json" id="pd3-bootstrap">
<?= json_encode([
    'range' => $range->asArray(),
    'links' => $linksJson,
    'csrf'  => $_SESSION['payday2_csrf'] ?? '',
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
