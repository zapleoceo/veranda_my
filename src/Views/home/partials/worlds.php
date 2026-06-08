<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * «Четыре мира на одной поляне». Editorial-split с оверсайз-нумералами и
 * double-bezel рамками; чередование сторон через --reverse. Цикл по $worlds (DRY).
 *
 * Медиа: если у «мира» несколько фото — слайдер-галерея; иначе одно фото.
 * У бани / GameZone / детской — ровно одна ссылка (требование); вторая кнопка
 * только у ресторана (меню + бронь).
 *
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Venue[]     $worlds
 */

$head = $content->heads()['worlds'];
$sizes = '(min-width: 860px) 50vw, 100vw';
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
                <?php if ($w->isGallery()): ?>
                <div class="frame__inner gallery" data-gallery>
                    <?php foreach ($w->images as $idx => $img): ?>
                    <?= Html::img($img, $w->imageAlt, $sizes, false, 'class="gallery__slide' . ($idx === 0 ? ' is-active' : '') . '"') ?>
                    <?php endforeach; ?>
                    <div class="gallery__dots" aria-hidden="true">
                        <?php foreach ($w->images as $idx => $img): ?>
                        <button class="gallery__dot<?= $idx === 0 ? ' is-active' : '' ?>" type="button" aria-label="Фото <?= $idx + 1 ?>"></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="frame__inner">
                    <?= Html::img($w->images[0], $w->imageAlt, $sizes) ?>
                </div>
                <?php endif; ?>
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
