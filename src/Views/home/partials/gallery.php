<?php

declare(strict_types=1);

use App\Home\View\Html;

/**
 * Bento-галерея атмосферы: фото из контента + цитата гостя.
 * Раскладку плиток задаёт CSS (nth-child) — порядок фото берётся из PageContent.
 *
 * @var \App\Home\Content\PageContent $content
 */

$head = $content->heads()['bento'];
$photos = $content->gallery();
$quote = $content->galleryQuote();
?>
<section class="bento">
    <div class="container">
        <div class="section__head reveal">
            <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
            <h2 class="h-section"><?= $head['titleHtml'] ?></h2>
        </div>
        <div class="bento__grid reveal">
            <?php foreach ($photos as $p): ?>
            <div class="bento__cell"><?= Html::img($p['name'], $p['alt'], '(min-width: 780px) 33vw, 50vw') ?></div>
            <?php endforeach; ?>
            <div class="bento__quote">
                <p><?= Html::e($quote['text']) ?></p>
                <cite><?= Html::e($quote['cite']) ?></cite>
            </div>
        </div>
    </div>
</section>
