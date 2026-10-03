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
                <?php /* Статичная карта (OSM, собрана scripts/ops/build_static_map.mjs, центр = Veranda):
                         без внешних тайлов/скриптов, поэтому не ломается. Клик — маршрут в Google Maps. */ ?>
                <a class="frame__inner location__map" href="<?= Html::e($contacts->maps) ?>" target="_blank" rel="noopener">
                    <?= Html::img('map-veranda', $lang->t('location.mapAlt'), '(min-width: 860px) 50vw, 100vw') ?>
                    <span class="map-pin" aria-hidden="true"><span class="map-pin__pulse"></span><span class="map-pin__dot"></span></span>
                    <span class="location__attr">© OpenStreetMap</span>
                </a>
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
