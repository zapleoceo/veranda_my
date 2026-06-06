<?php
/** @var \App\Payday3\Domain\DateRange $range */
declare(strict_types=1);
?>
<form class="pd3-filters" method="get" action="/payday3">
    <label class="pd3-filters__field">
        <span>Дата</span>
        <input type="date" name="dateFrom" value="<?= htmlspecialchars($range->from) ?>" required>
    </label>
    <input type="hidden" name="dateTo" value="<?= htmlspecialchars($range->from) ?>">
    <button type="submit" class="pd3-btn">Показать</button>
</form>
