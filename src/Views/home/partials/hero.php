<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 * @var \App\Home\I18n\Lang           $lang
 */
?>
<section class="hero" aria-label="Veranda">
    <div class="hero__media" aria-hidden="true">
        <?= Html::img('hero-terrace', '', '100vw', true) ?>
    </div>
    <div class="hero__veil" aria-hidden="true"></div>

    <div class="wrap hero__inner">
        <span class="eyebrow"><?= Html::e($lang->t('hero.eyebrow')) ?></span>
        <h1 class="display hero__title"><?= $content->heroTitleHtml() ?></h1>
        <p class="lead hero__lead"><?= Html::e($content->heroLead()) ?></p>
        <div class="hero__cta">
            <a class="btn btn--primary" href="<?= Html::e($contacts->reserve) ?>" data-magnetic><?= Html::e($lang->t('hero.cta1')) ?> <span class="btn__ic"><?= Icons::get('arrow') ?></span></a>
            <a class="btn btn--ghost" href="#worlds"><?= Html::e($lang->t('hero.cta2')) ?> <span class="btn__ic"><?= Icons::get('chevron-down') ?></span></a>
        </div>
    </div>

    <div class="hero__side" aria-hidden="true"><?= Html::e($contacts->coords) ?> · <?= Html::e($lang->t('hero.side')) ?></div>
    <div class="hero__scroll" aria-hidden="true"><span>Scroll</span></div>
</section>
