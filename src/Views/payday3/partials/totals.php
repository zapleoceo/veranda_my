<?php
/** @var \App\Payday3\Domain\SepayTransaction[]  $sepayOpen */
/** @var \App\Payday3\Domain\SepayTransaction[]  $sepayHidden */
/** @var \App\Payday3\Domain\PosterTransaction[] $poster */
declare(strict_types=1);

use App\Payday3\Domain\Money;

// Vietnam Company poster checks aren't routed through the bank, so
// they don't appear in Sepay. Bank deposits = "card receipts" + the
// VC bucket — Δ has to subtract BOTH to be meaningful.
const PD3_TOTALS_METHOD_VIETNAM = 11;

$sepayTotal = Money::vnd(0);
foreach ($sepayOpen   as $s) $sepayTotal = $sepayTotal->plus($s->amount);
foreach ($sepayHidden as $s) $sepayTotal = $sepayTotal->plus($s->amount);

// Poster (excluding Vietnam) — mirrors the pane-footer "Итого":
// card + third + tip_sum, excluding poster_payment_method_id = 11.
$posterTotal   = Money::vnd(0);
$vietnamTotal  = Money::vnd(0);
foreach ($poster as $p) {
    $rowTotal = $p->payedCard->plus($p->payedThirdParty)->plus($p->tipSum);
    if ((int)$p->posterPaymentMethodId === PD3_TOTALS_METHOD_VIETNAM) {
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
    <div>Sepay: <strong><?= htmlspecialchars($sepayTotal->format()) ?></strong></div>
    <div>Poster: <strong><?= htmlspecialchars($posterTotal->format()) ?></strong></div>
    <div>VC: <strong><?= htmlspecialchars($vietnamTotal->format()) ?></strong></div>
    <div class="<?= $diff->isZero() ? 'ok' : ($diff->isNegative() ? 'danger' : 'warn') ?>">
        Δ: <strong><?= htmlspecialchars($diff->format()) ?></strong>
    </div>
</section>
