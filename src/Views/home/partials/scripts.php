<?php

declare(strict_types=1);

/**
 * JSON-LD (из Seo) + единственный внешний JS-файл. defer — DOM готов к запуску.
 *
 * @var \App\Home\Content\Seo         $seo
 * @var \App\Home\Content\PageContent $content
 */

$v = '20260926a';
?>
<script type="application/ld+json"><?= json_encode($seo->jsonLd(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script type="application/ld+json"><?= json_encode($seo->faqJsonLd($content->faq()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/home.js?v=<?= $v ?>" defer></script>
