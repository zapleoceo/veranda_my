<?php

declare(strict_types=1);

use App\Home\I18n\Locale;
use App\Home\View\Html;

/**
 * Корневой шаблон /home. Только композиция партиалов.
 *
 * @var \App\Home\View\View           $view
 * @var \App\Home\I18n\Lang           $lang
 * @var string                        $locale
 * @var \App\Home\Content\Seo         $seo
 * @var \App\Home\Content\PageContent $content
 * @var \App\Home\Content\Contacts    $contacts
 * @var \App\Home\Content\WeeklyProgram $program
 * @var \App\Home\Content\Venue[]     $worlds
 */
?><!doctype html>
<html lang="<?= Html::e(Locale::htmlLang($locale)) ?>">
<?= $view->partial('partials/head', ['seo' => $seo, 'lang' => $lang, 'locale' => $locale]) ?>
<body>
<?= $view->partial('partials/header',   ['contacts' => $contacts, 'seo' => $seo, 'lang' => $lang, 'locale' => $locale]) ?>
<?= $view->partial('partials/hero',     ['content' => $content, 'contacts' => $contacts, 'lang' => $lang]) ?>
<?= $view->partial('partials/marquee',  ['items' => $content->marquee()]) ?>
<?= $view->partial('partials/tonight',  ['content' => $content, 'program' => $program, 'contacts' => $contacts, 'lang' => $lang]) ?>
<?= $view->partial('partials/worlds',   ['content' => $content, 'worlds' => $worlds]) ?>
<?= $view->partial('partials/gallery',  ['content' => $content]) ?>
<?= $view->partial('partials/gazebos',  ['content' => $content, 'contacts' => $contacts, 'lang' => $lang]) ?>
<?= $view->partial('partials/location', ['content' => $content, 'contacts' => $contacts, 'lang' => $lang]) ?>
<?= $view->partial('partials/footer',   ['content' => $content, 'contacts' => $contacts, 'lang' => $lang]) ?>
<?= $view->partial('partials/mobile-cta', ['contacts' => $contacts, 'lang' => $lang]) ?>
<?= $view->partial('partials/scripts',  ['seo' => $seo]) ?>
</body>
</html>
