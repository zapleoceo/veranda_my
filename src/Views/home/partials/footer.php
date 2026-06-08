<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 * @var \App\Home\I18n\Lang           $lang
 */
?>
<footer class="ftr">
    <div class="wrap">
        <div class="ftr__top">
            <div>
                <div class="ftr__brand">VERANDA</div>
                <p class="ftr__tagline"><?= Html::e($lang->t('footer.tagline')) ?></p>
                <div class="ftr__socials">
                    <a href="<?= Html::e($contacts->whatsApp()) ?>"  target="_blank" rel="noopener" aria-label="WhatsApp"><?= Icons::get('wa') ?></a>
                    <a href="<?= Html::e($contacts->telegram) ?>"   target="_blank" rel="noopener" aria-label="Telegram"><?= Icons::get('tg') ?></a>
                    <a href="<?= Html::e($contacts->instagram) ?>"  target="_blank" rel="noopener" aria-label="Instagram"><?= Icons::get('ig') ?></a>
                    <a href="<?= Html::e($contacts->tel()) ?>" aria-label="Phone"><?= Icons::get('phone') ?></a>
                </div>
            </div>
            <div class="ftr__col">
                <h4><?= Html::e($lang->t('footer.colVeranda')) ?></h4>
                <ul>
                    <li><a href="<?= Html::e($contacts->reserve) ?>"><?= Html::e($lang->t('footer.book')) ?></a></li>
                    <li><a href="<?= Html::e($contacts->menu) ?>"><?= Html::e($lang->t('footer.menu')) ?></a></li>
                    <li><a href="#tonight"><?= Html::e($lang->t('footer.schedule')) ?></a></li>
                    <li><a href="/links/"><?= Html::e($lang->t('footer.alllinks')) ?></a></li>
                </ul>
            </div>
            <div class="ftr__col">
                <h4><?= Html::e($lang->t('footer.colPartners')) ?></h4>
                <ul>
                    <li><a href="<?= Html::e($contacts->banyaSite) ?>"    target="_blank" rel="noopener"><?= Html::e($lang->t('footer.partnerBanya')) ?></a></li>
                    <li><a href="<?= Html::e($contacts->gamezoneSite) ?>" target="_blank" rel="noopener">GameZone</a></li>
                    <li><a href="<?= Html::e($contacts->kidsInstagram) ?>" target="_blank" rel="noopener">Ananas Party</a></li>
                </ul>
            </div>
            <div class="ftr__col">
                <h4><?= Html::e($lang->t('footer.colContacts')) ?></h4>
                <ul>
                    <li><a href="<?= Html::e($contacts->tel()) ?>"><?= Html::e($contacts->phoneDisplay) ?></a></li>
                    <li><a href="<?= Html::e($contacts->director) ?>" target="_blank" rel="noopener"><?= Html::e($lang->t('footer.director')) ?></a></li>
                </ul>
            </div>
        </div>
        <div class="ftr__bottom">© <?= date('Y') ?> Veranda</div>
    </div>
</footer>
