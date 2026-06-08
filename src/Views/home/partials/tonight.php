<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * Афиша. «Сегодня» рендерится на сервере (корректно без JS и для SEO);
 * каждая карточка дня несёт data-* с полными данными события, чтобы JS мог
 * пере-выбрать день на клиенте (на случай страницы, закешированной через
 * полночь) — без дублирования данных событий в JS.
 *
 * @var \App\Home\Content\PageContent   $content
 * @var \App\Home\Content\WeeklyProgram $program
 * @var \App\Home\Content\Contacts      $contacts
 */

$head = $content->heads()['tonight'];
$todayIdx = $program->todayIndex();
$today = $program->today();
$fullDay = ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'][$todayIdx] ?? '';
?>
<section id="tonight" class="tonight">
    <div class="container">
        <div class="section__head reveal">
            <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
            <h2 class="h-section"><?= $head['titleHtml'] ?></h2>
            <p class="lead"><?= Html::e($head['lead']) ?></p>
        </div>

        <div class="tonight__hero reveal">
            <div class="tonight__hero-bg" aria-hidden="true">
                <?= Html::img('lanterns-city', '', '100vw') ?>
            </div>
            <div class="tonight__hero-inner">
                <div class="tonight__day" id="tonightDay"><?= Html::e($fullDay) ?></div>
                <div class="tonight__info">
                    <span class="badge">Сегодня вечером</span>
                    <h3 id="tonightTitle"><?= Html::e($today->title . ' · ' . $today->time) ?></h3>
                    <p id="tonightNote"><?= Html::e($today->note) ?></p>
                </div>
                <a class="btn btn--red" href="<?= Html::e($contacts->reserve) ?>">Забронировать <?= Icons::get('arrow') ?></a>
            </div>
        </div>

        <div class="tonight__week reveal">
            <?php foreach ($program->week() as $day => $ev): ?>
            <div class="tonight__day-card<?= $day === $todayIdx ? ' is-today' : '' ?>"
                 data-day="<?= (int) $day ?>"
                 data-title="<?= Html::e($ev->title) ?>"
                 data-time="<?= Html::e($ev->time) ?>"
                 data-note="<?= Html::e($ev->note) ?>">
                <div class="tonight__day-card-name"><?= Html::e($program->dayName($day)) ?></div>
                <div class="tonight__day-card-title"><?= Html::e($ev->title) ?></div>
                <div class="tonight__day-card-time"><?= Html::e($ev->time) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="tonight__free reveal">Вход на все события — свободный</p>
    </div>
</section>
