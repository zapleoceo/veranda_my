<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 */
?>
<section class="hero" aria-label="Главная">
    <div class="hero__media" aria-hidden="true">
        <?= Html::img('hero-terrace', 'Терраса Veranda с красными фонарями и видом на горы Нячанга', '100vw', true) ?>
    </div>
    <div class="hero__inner">
        <div class="hero__eyebrow">
            <b>Veranda Restaurant &amp; Bar</b><span></span><b>Nha Trang · Vietnam</b>
        </div>
        <h1><?= $content->heroTitleHtml() ?></h1>
        <p class="hero__lead"><?= Html::e($content->heroLead()) ?></p>
        <div class="hero__cta">
            <a class="btn btn--red" href="<?= Html::e($contacts->reserve) ?>">Забронировать столик <?= Icons::get('arrow') ?></a>
            <a class="btn btn--ghost-light" href="#tonight">Что сегодня вечером</a>
        </div>
    </div>
    <div class="hero__scroll" aria-hidden="true"><span>Scroll</span></div>
</section>
