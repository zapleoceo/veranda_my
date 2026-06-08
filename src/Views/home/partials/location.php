<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 */

$head = $content->heads()['location'];
?>
<section class="location">
    <div class="container">
        <div class="location__inner">
            <div class="location__media reveal">
                <?= Html::img('mountain-view', 'Вид на горы со столика Veranda', '(min-width: 780px) 50vw, 100vw') ?>
            </div>
            <div class="reveal">
                <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
                <h2 class="h-section"><?= $head['titleHtml'] ?></h2>
                <p class="lead"><?= Html::e($head['lead']) ?></p>
                <div class="location__facts">
                    <ul>
                        <li><?= Icons::get('pin') ?> <span><?= Html::e($content->directions()) ?></span></li>
                        <li><?= Icons::get('phone') ?> <span><a href="<?= Html::e($contacts->tel()) ?>"><?= Html::e($contacts->phoneDisplay) ?></a></span></li>
                        <li><?= Icons::get('clock') ?> <span><?= Html::e($content->hours()) ?></span></li>
                    </ul>
                </div>
                <div class="location__cta">
                    <a class="btn btn--red" href="<?= Html::e($contacts->maps) ?>" target="_blank" rel="noopener">Построить маршрут <?= Icons::get('arrow') ?></a>
                    <a class="btn btn--outline" href="<?= Html::e($contacts->telegram) ?>" target="_blank" rel="noopener">Спросить дорогу</a>
                </div>
            </div>
        </div>
    </div>
</section>
