<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * Липкая нижняя панель действий для мобильных (на десктопе скрыта через CSS).
 *
 * @var \App\Home\Content\Contacts $contacts
 */
?>
<div class="mob-cta">
    <a class="mob-cta__primary"   href="<?= Html::e($contacts->reserve) ?>">Забронировать <?= Icons::get('arrow') ?></a>
    <a class="mob-cta__secondary" href="<?= Html::e($contacts->menu) ?>">Меню</a>
</div>
