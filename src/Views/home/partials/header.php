<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\Contacts $contacts
 * @var \App\Home\Content\Seo      $seo
 */
?>
<header class="hdr" id="hdr">
    <div class="container hdr__row">
        <a class="hdr__brand" href="<?= Html::e($seo->canonical) ?>">VERANDA</a>
        <div class="hdr__actions">
            <div class="hdr__lang" role="group" aria-label="Язык">
                <button type="button" aria-pressed="true"  data-lang="ru">RU</button><span>·</span>
                <button type="button" aria-pressed="false" data-lang="en">EN</button><span>·</span>
                <button type="button" aria-pressed="false" data-lang="vi">VI</button><span>·</span>
                <button type="button" aria-pressed="false" data-lang="ko">KO</button>
            </div>
            <a class="hdr__icon" href="<?= Html::e($contacts->whatsApp()) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= Icons::get('wa') ?></a>
            <a class="hdr__icon" href="<?= Html::e($contacts->telegram) ?>" target="_blank" rel="noopener" aria-label="Telegram"><?= Icons::get('tg') ?></a>
            <a class="hdr__cta" href="<?= Html::e($contacts->reserve) ?>">Забронировать</a>
        </div>
    </div>
</header>
