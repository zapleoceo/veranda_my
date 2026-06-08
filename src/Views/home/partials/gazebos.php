<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 */

$g = $content->gazebos();
?>
<section class="gazebos">
    <div class="container">
        <div class="gazebos__split reveal">
            <div class="gazebos__media">
                <?= Html::img('gazebo-inside', 'Беседка с тканевыми занавесками внутри', '(min-width: 780px) 50vw, 100vw') ?>
            </div>
            <div class="gazebos__text">
                <span class="eyebrow eyebrow--soft">Приватно</span>
                <h3><?= Html::e($g['title']) ?></h3>
                <p><?= Html::e($g['lead']) ?></p>
                <a class="btn btn--light" href="<?= Html::e($contacts->reserve) ?>">Забронировать беседку <?= Icons::get('arrow') ?></a>
            </div>
        </div>
    </div>
</section>
