<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 */
?>
<footer class="ftr">
    <div class="container">
        <div class="ftr__top">
            <div>
                <div class="ftr__brand">VERANDA</div>
                <p class="ftr__tagline">Ресторан, баня и игры на одной поляне в горах Нячанга. Бронирование столика — через сайт.</p>
                <div class="ftr__socials">
                    <a href="<?= Html::e($contacts->whatsApp()) ?>"  target="_blank" rel="noopener" aria-label="WhatsApp"><?= Icons::get('wa') ?></a>
                    <a href="<?= Html::e($contacts->telegram) ?>"   target="_blank" rel="noopener" aria-label="Telegram"><?= Icons::get('tg') ?></a>
                    <a href="<?= Html::e($contacts->instagram) ?>"  target="_blank" rel="noopener" aria-label="Instagram"><?= Icons::get('ig') ?></a>
                    <a href="<?= Html::e($contacts->tel()) ?>" aria-label="Позвонить"><?= Icons::get('phone') ?></a>
                </div>
            </div>
            <div>
                <h4>Veranda</h4>
                <ul>
                    <li><a href="<?= Html::e($contacts->reserve) ?>">Бронь столика</a></li>
                    <li><a href="<?= Html::e($contacts->menu) ?>">Меню</a></li>
                    <li><a href="#tonight">Афиша недели</a></li>
                    <li><a href="/links/">Все ссылки</a></li>
                </ul>
            </div>
            <div>
                <h4>Партнёры</h4>
                <ul>
                    <li><a href="<?= Html::e($contacts->banyaSite) ?>"    target="_blank" rel="noopener">Баня «Сила Духа»</a></li>
                    <li><a href="<?= Html::e($contacts->gamezoneSite) ?>" target="_blank" rel="noopener">GameZone</a></li>
                </ul>
            </div>
            <div>
                <h4>Контакты</h4>
                <ul>
                    <li><a href="<?= Html::e($contacts->tel()) ?>"><?= Html::e($contacts->phoneDisplay) ?></a></li>
                    <li><a href="<?= Html::e($contacts->whatsApp()) ?>" target="_blank" rel="noopener">WhatsApp</a></li>
                    <li>Nha Trang, Việt Nam</li>
                </ul>
            </div>
        </div>
        <div class="ftr__bottom">
            © <?= date('Y') ?> Veranda · Бронирование через <a href="<?= Html::e($contacts->reserve) ?>">veranda.my/tr3</a>
        </div>
    </div>
</footer>
