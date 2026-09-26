<?php

declare(strict_types=1);

use App\Home\View\Html;
use App\Home\View\Icons;

/**
 * Видимый блок «Вопросы и ответы». Те же пары уходят в FAQPage-разметку
 * (partials/scripts) — один источник, без расхождений текста и разметки.
 *
 * <details>/<summary> вместо JS: сворачивание нативное, доступно с клавиатуры,
 * и текст ответов остаётся в DOM — его видят краулеры и ИИ-парсеры.
 *
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\I18n\Lang           $lang
 */

$head = $content->heads()['faq'];
?>
<section id="faq" class="sec faq">
    <div class="wrap wrap--narrow">
        <div class="sec-head reveal">
            <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
            <h2 class="h2"><?= $head['titleHtml'] ?></h2>
        </div>

        <div class="faq__list reveal">
            <?php foreach ($content->faq() as $i => $item): ?>
            <details class="faq__item"<?= $i === 0 ? ' open' : '' ?>>
                <summary class="faq__q">
                    <span><?= Html::e($item['q']) ?></span>
                    <span class="faq__ic" aria-hidden="true"><?= Icons::get('chevron-down') ?></span>
                </summary>
                <p class="faq__a"><?= Html::e($item['a']) ?></p>
            </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>
