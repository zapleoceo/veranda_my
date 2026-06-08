<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * Плавающая «стеклянная» навигация + переключатель языков.
 *
 * @var \App\Home\Content\Contacts $contacts
 * @var \App\Home\Content\Seo      $seo
 * @var \App\Home\I18n\Lang        $lang
 * @var string                     $locale
 */
?>
<header class="nav" id="nav">
    <div class="nav__pill">
        <a class="nav__brand" href="<?= Html::e($seo->canonical()) ?>">VERANDA</a>
        <nav class="nav__links">
            <a href="#worlds"><?= Html::e($lang->t('nav.complex')) ?></a>
            <a href="#tonight"><?= Html::e($lang->t('nav.schedule')) ?></a>
            <a href="#location"><?= Html::e($lang->t('nav.location')) ?></a>
        </nav>
        <div class="nav__right">
            <div class="nav__lang" role="group" aria-label="<?= Html::e($lang->t('nav.switcher')) ?>">
                <a href="/en/"<?= $locale === 'en' ? ' class="is-active" aria-current="true"' : '' ?>>EN</a>
                <a href="/ru/"<?= $locale === 'ru' ? ' class="is-active" aria-current="true"' : '' ?>>RU</a>
                <a href="/vi/"<?= $locale === 'vi' ? ' class="is-active" aria-current="true"' : '' ?>>VI</a>
            </div>
            <a class="nav__ico" href="<?= Html::e($contacts->whatsApp()) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= Icons::get('wa') ?></a>
            <a class="nav__ico" href="<?= Html::e($contacts->telegram) ?>" target="_blank" rel="noopener" aria-label="Telegram"><?= Icons::get('tg') ?></a>
            <a class="btn btn--primary nav__cta" href="<?= Html::e($contacts->reserve) ?>" data-magnetic><?= Html::e($lang->t('nav.table')) ?> <span class="btn__ic"><?= Icons::get('arrow') ?></span></a>
        </div>
    </div>
</header>
