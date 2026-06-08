<?php

declare(strict_types=1);

use App\Home\View\Html;

/**
 * Атмосфера — светлая «paper»-интерлюдия (контрапункт тёмным секциям).
 * Асимметричный bento: 2 крупных + 3 средних кадра + цитата на всю ширину.
 *
 * @var \App\Home\Content\PageContent $content
 */

$head = $content->heads()['bento'];
$photos = $content->gallery();
$quote = $content->galleryQuote();
$cells = ['a', 'b', 'c', 'd', 'e'];
?>
<section class="sec sec--paper bento">
    <div class="wrap">
        <div class="sec-head reveal">
            <span class="eyebrow"><?= Html::e($head['eyebrow']) ?></span>
            <h2 class="h2"><?= $head['titleHtml'] ?></h2>
        </div>
        <div class="bento__grid reveal">
            <?php foreach ($photos as $i => $p): $cls = $cells[$i] ?? 'e'; ?>
            <div class="bento__cell bento__cell--<?= $cls ?> frame">
                <div class="frame__inner"><?= Html::img($p['name'], $p['alt'], '(min-width: 860px) 33vw, 50vw') ?></div>
            </div>
            <?php endforeach; ?>
            <figure class="bento__quote">
                <p><?= Html::e($quote['text']) ?></p>
                <cite><?= Html::e($quote['cite']) ?></cite>
            </figure>
        </div>
    </div>
</section>
