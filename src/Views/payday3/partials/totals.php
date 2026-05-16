<?php
/** @var \App\Payday3\Domain\SepayTransaction[]  $sepayOpen */
/** @var \App\Payday3\Domain\SepayTransaction[]  $sepayHidden */
/** @var \App\Payday3\Domain\PosterTransaction[] $poster */
declare(strict_types=1);

use App\Payday3\Domain\Money;

$sepayTotal = Money::vnd(0);
foreach ($sepayOpen   as $s) $sepayTotal = $sepayTotal->plus($s->amount);
foreach ($sepayHidden as $s) $sepayTotal = $sepayTotal->plus($s->amount);

$posterTotal = Money::vnd(0);
foreach ($poster as $p) $posterTotal = $posterTotal->plus($p->totalPayed());

$diff = $sepayTotal->minus($posterTotal);
?>
<section class="pd3-totals">
    <div>Sepay: <strong><?= htmlspecialchars($sepayTotal->format()) ?></strong></div>
    <div>Poster: <strong><?= htmlspecialchars($posterTotal->format()) ?></strong></div>
    <div class="<?= $diff->isZero() ? 'ok' : ($diff->isNegative() ? 'danger' : 'warn') ?>">
        Δ: <strong><?= htmlspecialchars($diff->format()) ?></strong>
    </div>
</section>
