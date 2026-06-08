<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * «Три мира на одной поляне». Блоки строятся циклом по $worlds (DRY) —
 * добавить/переставить «мир» = поправить VenueDirectory, не разметку.
 *
 * У бани и GameZone — ровно одна ссылка (требование). Вторая кнопка только
 * у самого ресторана (меню + бронь — его собственные конверсии).
 *
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Venue[]     $worlds
 */

$head = $content->heads()['worlds'];
?>
<section class="worlds">
    <div class="container">
        <div class="section__head reveal">
            <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
            <h2 class="h-section"><?= $head['titleHtml'] ?></h2>
            <p class="lead"><?= Html::e($head['lead']) ?></p>
        </div>

        <?php foreach ($worlds as $w): ?>
        <article class="world reveal<?= $w->reverse ? ' world--reverse' : '' ?>">
            <div class="world__media">
                <?= Html::img($w->image, $w->imageAlt, '(min-width: 780px) 50vw, 100vw') ?>
            </div>
            <div class="world__text">
                <div class="num"><?= Html::e($w->number) ?></div>
                <h3><?= $w->titleHtml ?></h3>
                <p><?= Html::e($w->lead) ?></p>
                <ul class="world__list">
                    <?php foreach ($w->tags as $tag): ?><li><?= Html::e($tag) ?></li><?php endforeach; ?>
                </ul>
                <div class="world__cta">
                    <a class="btn btn--red" href="<?= Html::e($w->linkUrl) ?>"<?= $w->external ? ' target="_blank" rel="noopener"' : '' ?>><?= Html::e($w->linkLabel) ?> <?= Icons::get('arrow') ?></a>
                    <?php if ($w->hasSecondary()): ?>
                    <a class="btn btn--outline" href="<?= Html::e($w->secondaryUrl) ?>"><?= Html::e($w->secondaryLabel) ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
</section>
