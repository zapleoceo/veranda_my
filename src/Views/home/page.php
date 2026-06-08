<?php

declare(strict_types=1);

/**
 * Корневой шаблон /home. Только композиция партиалов — без копирайта и логики.
 *
 * @var \App\Home\View\View          $view
 * @var \App\Home\Content\Seo         $seo
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 * @var \App\Home\Content\WeeklyProgram $program
 * @var \App\Home\Content\Venue[]     $worlds
 */
?><!doctype html>
<html lang="ru">
<?= $view->partial('partials/head', ['seo' => $seo]) ?>
<body>
<?= $view->partial('partials/header',   ['contacts' => $contacts, 'seo' => $seo]) ?>
<?= $view->partial('partials/hero',     ['content' => $content, 'contacts' => $contacts]) ?>
<?= $view->partial('partials/marquee',  ['items' => $content->marquee()]) ?>
<?= $view->partial('partials/tonight',  ['content' => $content, 'program' => $program, 'contacts' => $contacts]) ?>
<?= $view->partial('partials/worlds',   ['content' => $content, 'worlds' => $worlds]) ?>
<?= $view->partial('partials/gallery',  ['content' => $content]) ?>
<?= $view->partial('partials/gazebos',  ['content' => $content, 'contacts' => $contacts]) ?>
<?= $view->partial('partials/location', ['content' => $content, 'contacts' => $contacts]) ?>
<?= $view->partial('partials/footer',   ['content' => $content, 'contacts' => $contacts]) ?>
<?= $view->partial('partials/mobile-cta', ['contacts' => $contacts]) ?>
<?= $view->partial('partials/scripts',  ['seo' => $seo]) ?>
</body>
</html>
