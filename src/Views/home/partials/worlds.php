<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * «Три мира на одной поляне». Editorial-split с оверсайз-нумералами и
 * double-bezel рамками; чередование сторон через --reverse. Цикл по $worlds (DRY).
 *
 * Баня и GameZone — ровно одна ссылка (требование); вторая кнопка только у
 * ресторана (меню + бронь — его собственные конверсии).
 *
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Venue[]     $worlds
 */

$head = $content->heads()['worlds'];
?>
<section id="worlds" class="sec worlds">
    <div class="wrap">
        <div class="sec-head reveal">
            <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
            <h2 class="h2"><?= $head['titleHtml'] ?></h2>
            <p class="lead"><?= Html::e($head['lead']) ?></p>
        </div>

        <?php foreach ($worlds as $w): ?>
        <article class="world reveal<?= $w->reverse ? ' world--reverse' : '' ?>">
            <div class="world__media frame">
                <div class="frame__inner">
                    <?= Html::img($w->image, $w->imageAlt, '(min-width: 860px) 50vw, 100vw') ?>
                </div>
            </div>
            <div class="world__body">
                <div class="world__index index"><?= Html::e($w->index) ?></div>
                <span class="eyebrow world__label"><?= Html::e($w->label) ?></span>
                <h3 class="h2 world__title"><?= $w->titleHtml ?></h3>
                <p class="lead world__lead"><?= Html::e($w->lead) ?></p>
                <ul class="chips">
                    <?php foreach ($w->tags as $tag): ?><li><?= Html::e($tag) ?></li><?php endforeach; ?>
                </ul>
                <div class="world__cta">
                    <a class="btn btn--primary" href="<?= Html::e($w->linkUrl) ?>"<?= $w->external ? ' target="_blank" rel="noopener"' : '' ?> data-magnetic><?= Html::e($w->linkLabel) ?> <span class="btn__ic"><?= Icons::get($w->external ? 'arrow-ne' : 'arrow') ?></span></a>
                    <?php if ($w->hasSecondary()): ?>
                    <a class="btn btn--ghost" href="<?= Html::e($w->secondaryUrl) ?>"><?= Html::e($w->secondaryLabel) ?> <span class="btn__ic"><?= Icons::get('arrow') ?></span></a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
