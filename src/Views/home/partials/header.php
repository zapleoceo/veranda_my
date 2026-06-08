<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * Плавающая «стеклянная» навигация (floating glass pill), отделённая от верха.
 *
 * @var \App\Home\Content\Contacts $contacts
 * @var \App\Home\Content\Seo      $seo
 */
?>
<header class="nav" id="nav">
    <div class="nav__pill">
        <a class="nav__brand" href="<?= Html::e($seo->canonical) ?>">VERANDA</a>
        <nav class="nav__links" aria-label="Разделы">
            <a href="#worlds">Комплекс</a>
            <a href="#tonight">Афиша</a>
            <a href="#location">Локация</a>
        </nav>
        <div class="nav__right">
            <a class="nav__ico" href="<?= Html::e($contacts->whatsApp()) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= Icons::get('wa') ?></a>
            <a class="nav__ico" href="<?= Html::e($contacts->telegram) ?>" target="_blank" rel="noopener" aria-label="Telegram"><?= Icons::get('tg') ?></a>
            <a class="btn btn--primary nav__cta" href="<?= Html::e($contacts->reserve) ?>" data-magnetic>Столик <span class="btn__ic"><?= Icons::get('arrow') ?></span></a>
        </div>
    </div>
</header>
