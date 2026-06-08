<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * Афиша. «Сегодня» рендерится на сервере (корректно без JS и для SEO).
 * Карточки дня — кнопки: клик меняет featured-карточку (день, текст, фон-фото
 * и ссылку кнопки). Данные события лежат в data-* — в JS не дублируются.
 *
 * @var \App\Home\Content\PageContent   $content
 * @var \App\Home\Content\WeeklyProgram $program
 * @var \App\Home\Content\Contacts      $contacts
 */

$head = $content->heads()['tonight'];
$todayIdx = $program->todayIndex();
$today = $program->today();
?>
<section id="tonight" class="sec tonight">
    <div class="wrap">
        <div class="sec-head reveal">
            <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
            <h2 class="h2"><?= $head['titleHtml'] ?></h2>
            <p class="lead"><?= Html::e($head['lead']) ?></p>
        </div>

        <div class="tonight__feature frame reveal">
            <div class="frame__inner tonight__feature-in">
                <div class="tonight__feature-bg" aria-hidden="true">
                    <?= Html::img($today->image, '', '100vw', false, 'class="is-active" data-bg') ?>
                    <img data-bg alt="" aria-hidden="true">
                </div>
                <div class="tonight__feature-body">
                    <div class="tonight__day" id="tonightDay"><?= Html::e($program->dayFullName($todayIdx)) ?></div>
                    <div class="tonight__feature-info">
                        <span class="tonight__badge" id="tonightBadge">Сегодня вечером</span>
                        <h3 class="tonight__title" id="tonightTitle"><?= Html::e($today->title . ' · ' . $today->time) ?></h3>
                        <p class="tonight__note" id="tonightNote"><?= Html::e($today->note) ?></p>
                    </div>
                    <a class="btn btn--primary" id="tonightCta" href="<?= Html::e($today->url) ?>" data-magnetic>Забронировать <span class="btn__ic"><?= Icons::get('arrow') ?></span></a>
                </div>
            </div>
        </div>

        <div class="tonight__week reveal">
            <?php foreach ($program->week() as $day => $ev): ?>
            <button type="button"
                    class="tonight__day-card<?= $day === $todayIdx ? ' is-active' : '' ?>"
                    data-day="<?= (int) $day ?>"
                    data-dayname="<?= Html::e($program->dayFullName($day)) ?>"
                    data-title="<?= Html::e($ev->title) ?>"
                    data-time="<?= Html::e($ev->time) ?>"
                    data-note="<?= Html::e($ev->note) ?>"
                    data-image="<?= Html::e($ev->image) ?>"
                    data-url="<?= Html::e($ev->url) ?>">
                <span class="tonight__day-card-name"><?= Html::e($program->dayName($day)) ?></span>
                <span class="tonight__day-card-title"><?= Html::e($ev->title) ?></span>
                <span class="tonight__day-card-time"><?= Html::e($ev->time) ?></span>
            </button>
            <?php endforeach; ?>
        </div>

        <p class="tonight__free reveal">Вход на все события — свободный</p>
    </div>
</section>
