<?php
declare(strict_types=1);
/**
 * Saved «Итоговый баланс» screenshots, at the very bottom of the page.
 * Filled by ui/shots.js from GET /payday3/api/balances/shots. The newest
 * one is marked: it is the one the 08:00 daily send posts.
 */
?>
<section class="pd3-card pd3-shots" id="pd3Shots">
    <header class="pd3-card__header">
        <h3>Снимки баланса</h3>
        <span class="pd3-shots__status muted" id="pd3ShotsStatus"></span>
    </header>
    <div class="pd3-shots__grid" id="pd3ShotsGrid">
        <p class="muted pd3-shots__empty" id="pd3ShotsEmpty" hidden>Снимков пока нет. Нажмите ✈ в «Итоговый баланс».</p>
    </div>
</section>
