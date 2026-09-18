<?php
/** @var \App\Payday3\Domain\SepayTransaction[]  $sepayOpen */
/** @var \App\Payday3\Domain\SepayTransaction[]  $sepayHidden */
/** @var \App\Payday3\Domain\PosterTransaction[] $poster */
declare(strict_types=1);

use App\Payday3\Domain\Money;
use App\Payday3\Domain\PosterIds;

// Vietnam Company poster checks aren't routed through the bank, so
// they don't appear in Sepay. Bank deposits = "card receipts" + the
// VC bucket — Δ has to subtract BOTH to be meaningful.
// (Method id: PosterIds::METHOD_VIETNAM — a template-level `const` would
// fatal on a second render.)

$sepayTotal = Money::vnd(0);
foreach ($sepayOpen   as $s) $sepayTotal = $sepayTotal->plus($s->amount);
foreach ($sepayHidden as $s) $sepayTotal = $sepayTotal->plus($s->amount);

// Poster (excluding Vietnam) — mirrors the pane-footer "Итого":
// card + third + tip_sum, excluding poster_payment_method_id = 11.
$posterTotal   = Money::vnd(0);
$vietnamTotal  = Money::vnd(0);
foreach ($poster as $p) {
    $rowTotal = $p->payedCard->plus($p->payedThirdParty)->plus($p->tipSum);
    if ((int)$p->posterPaymentMethodId === PosterIds::METHOD_VIETNAM) {
        $vietnamTotal = $vietnamTotal->plus($rowTotal);
    } else {
        $posterTotal = $posterTotal->plus($rowTotal);
    }
}

// Δ = Sepay − (Poster + VC). If the reconciliation is perfect this
// is zero; positive means Sepay has more inflow than Poster recorded;
// negative means Poster recorded more than the bank shows.
$diff = $sepayTotal->minus($posterTotal)->minus($vietnamTotal);
?>
<section class="pd3-totals">
    <div>Sepay: <strong id="pd3TotSepay"><?= htmlspecialchars($sepayTotal->format()) ?></strong></div>
    <div>Poster: <strong id="pd3TotPoster"><?= htmlspecialchars($posterTotal->format()) ?></strong></div>
    <div>VC: <strong id="pd3TotVc"><?= htmlspecialchars($vietnamTotal->format()) ?></strong></div>
    <div id="pd3TotDiffBox" class="<?= $diff->isZero() ? 'ok' : ($diff->isNegative() ? 'danger' : 'warn') ?>">
        Δ: <strong id="pd3TotDiff"><?= htmlspecialchars($diff->format()) ?></strong>
    </div>
</section>
