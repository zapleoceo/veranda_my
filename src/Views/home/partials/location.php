<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 * @var \App\Home\I18n\Lang           $lang
 */

$head = $content->heads()['location'];
?>
<section id="location" class="sec location">
    <div class="wrap">
        <div class="location__grid">
            <div class="location__media frame reveal">
                <div class="frame__inner location__map" id="locationMap"
                     data-lat="<?= Html::e($contacts->mapLat) ?>" data-lng="<?= Html::e($contacts->mapLng) ?>"
                     role="img" aria-label="<?= Html::e($lang->t('location.mapAlt')) ?>"></div>
            </div>
            <div class="reveal">
                <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
                <h2 class="h2"><?= $head['titleHtml'] ?></h2>
                <p class="lead"><?= Html::e($head['lead']) ?></p>
                <p class="location__coords"><?= Html::e($contacts->coords) ?> · <?= Html::e($lang->t('hero.side')) ?></p>
                <ul class="facts">
                    <li><span class="facts__ico"><?= Icons::get('pin') ?></span><span class="facts__txt"><?= Html::e($content->directions()) ?></span></li>
                    <li><span class="facts__ico"><?= Icons::get('clock') ?></span><span class="facts__txt"><?= Html::e($content->hours()) ?></span></li>
                </ul>
                <div class="location__cta">
                    <a class="btn btn--primary" href="<?= Html::e($contacts->maps) ?>" target="_blank" rel="noopener" data-magnetic><?= Html::e($lang->t('location.route')) ?> <span class="btn__ic"><?= Icons::get('arrow-ne') ?></span></a>
                </div>
            </div>
        </div>
    </div>
</section>
