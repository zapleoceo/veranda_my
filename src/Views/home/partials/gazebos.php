<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 * @var \App\Home\I18n\Lang           $lang
 */

$g = $content->gazebos();
?>
<section class="sec gazebos">
    <div class="wrap">
        <div class="gazebos__grid reveal">
            <div class="gazebos__media frame">
                <div class="frame__inner">
                    <?= Html::img('gazebo-inside', $lang->t('gazebos.alt'), '(min-width: 860px) 55vw, 100vw') ?>
                </div>
            </div>
            <div class="gazebos__body">
                <span class="eyebrow"><?= Html::e($lang->t('gazebos.eyebrow')) ?></span>
                <h2 class="h2"><?= Html::e($g['title']) ?></h2>
                <p class="lead"><?= Html::e($g['lead']) ?></p>
                <a class="btn btn--primary" href="<?= Html::e($contacts->reserve) ?>" data-magnetic><?= Html::e($lang->t('gazebos.button')) ?> <span class="btn__ic"><?= Icons::get('arrow') ?></span></a>
            </div>
        </div>
    </div>
</section>
