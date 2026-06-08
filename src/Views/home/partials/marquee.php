<?php

declare(strict_types=1);

use App\Home\View\Html;

/**
 * Бегущая строка. Пункты выводятся дважды — чтобы CSS-анимация
 * translateX(-50%) зацикливалась бесшовно (дублирование циклом, не копипастом).
 *
 * @var string[] $items
 */
?>
<div class="marquee" aria-hidden="true">
    <div class="marquee__track">
        <?php for ($pass = 0; $pass < 2; $pass++): ?>
            <?php foreach ($items as $item): ?>
                <span><?= Html::e($item) ?></span><span>·</span>
            <?php endforeach; ?>
        <?php endfor; ?>
    </div>
</div>
