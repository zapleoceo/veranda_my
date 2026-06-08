<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * Липкая нижняя панель действий для мобильных (на десктопе скрыта через CSS).
 *
 * @var \App\Home\Content\Contacts $contacts
 * @var \App\Home\I18n\Lang        $lang
 */
?>
<div class="mob-cta">
    <a class="btn btn--primary"  href="<?= Html::e($contacts->reserve) ?>"><?= Html::e($lang->t('hero.cta1')) ?> <span class="btn__ic"><?= Icons::get('arrow') ?></span></a>
    <a class="btn btn--ghost"    href="<?= Html::e($contacts->menu) ?>"><?= Html::e($lang->t('footer.menu')) ?></a>
</div>
