<?php

declare(strict_types=1);

use App\Home\View\Html;

/**
 * Бегущая строка. Пункты выводятся дважды — чтобы CSS-анимация
 * translateX(-50%) зацикливалась бесшовно. Дублирование делает цикл, а не
 * копипаст в разметке (DRY).
 *
 * @var string[] $items
 */
?>
<div class="strip" aria-hidden="true">
    <div class="strip__track">
        <?php for ($pass = 0; $pass < 2; $pass++): ?>
            <?php foreach ($items as $item): ?>
                <span><?= Html::e($item) ?></span><span>·</span>
            <?php endforeach; ?>
        <?php endfor; ?>
    </div>
</div>
