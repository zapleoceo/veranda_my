<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\SepayTransaction;
use PHPUnit\Framework\TestCase;

/**
 * Левая таблица «Деньги» единой страницы payday3.
 *
 * Одна <table>, три блока: поступления SePay (рисует сервер) → разделитель
 * «Расходы» → расходы из писем BIDV (дорисовывает JS). Строки поступлений
 * рисуются ещё и JS-ом после синка (in/renderTables.js), строки расходов —
 * только JS-ом (out/renderTables.js). Число колонок и кнопка «+» обязаны
 * совпадать везде — JS-половину держит tests/js/payday3/renderTables.test.mjs.
 */
final class BankTablePartialTest extends TestCase
{
    private const PARTIAL = __DIR__ . '/../../../src/Views/payday3/partials/bank_table.php';

    /** @param list<SepayTransaction> $open @param list<SepayTransaction> $hidden */
    private function render(array $open, array $hidden = [], array $rowState = []): string
    {
        $sepayOpen       = $open;
        $sepayHidden     = $hidden;
        $rowStateBySepay = $rowState;
        ob_start();
        require self::PARTIAL;
        return (string)ob_get_clean();
    }

    private function tx(int $id, int $amount, string $date): SepayTransaction
    {
        return SepayTransaction::fromRow([
            'sepay_id' => $id, 'transaction_date' => $date, 'transfer_amount' => $amount,
            'payment_method' => 'Card', 'content' => 'CK ' . $id, 'reference_code' => 'R' . $id,
        ]);
    }

    /** @return list<string> HTML строк <tr id="pd3-sepay-…"> */
    private function rows(string $html): array
    {
        preg_match_all('#<tr id="pd3-sepay-\d+".*?</tr>#s', $html, $m);
        return $m[0];
    }

    /** @return array<string,string> tbody id|class → его HTML, в порядке документа */
    private function tbodies(string $html): array
    {
        preg_match_all('#<tbody(?: id="([^"]+)")?(?: class="([^"]+)")?>(.*?)</tbody>#s', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $t) $out[$t[1] !== '' ? $t[1] : $t[2]] = $t[3];
        return $out;
    }

    public function test_one_table_income_block_then_divider_then_expense_block(): void
    {
        $html = $this->render([$this->tx(1, 1000, '2026-09-18 10:00:00')]);

        $this->assertSame(1, substr_count($html, '<table '), 'поступления и расходы — в ОДНОЙ таблице');
        $this->assertSame(
            ['pd3SepayTbody', 'pd3-bank-split', 'pd3OutMailTbody'],
            array_keys($this->tbodies($html)),
            'порядок: поступления → разделитель → расходы',
        );
        $this->assertStringContainsString('<td colspan="7">Расходы</td>', $html, 'разделитель подписан');
    }

    public function test_income_rows_live_in_the_income_block_with_income_create_button(): void
    {
        $html = $this->render([$this->tx(62165230, 350000, '2026-09-18 14:05:09')]);
        $income = $this->tbodies($html)['pd3SepayTbody'];

        $rows = $this->rows($income);
        $this->assertCount(1, $rows);
        $this->assertMatchesRegularExpression(
            '#<button type="button" class="pd3-row-create" title="Создать приход в Poster на эту сумму"'
            . ' data-tx-type="1" data-amount="350000" data-date="2026-09-18 14:05:09">\+</button>#',
            $rows[0],
            'кнопка «+» у поступления создаёт ПРИХОД на сумму и время строки'
        );
        $this->assertStringNotContainsString('pd3-sepay-', $this->tbodies($html)['pd3OutMailTbody'],
            'в блоке расходов нет строк поступлений');
    }

    public function test_hidden_rows_also_carry_button_but_are_not_row_red(): void
    {
        // Видимость «+» решает CSS: только tr.row-red. Скрытые строки
        // получают row-hidden — кнопка в них есть, но не показывается.
        $html = $this->render([], [$this->tx(5, 1000, '2026-09-18 10:00:00')]);

        $rows = $this->rows($html);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('class="pd3-row-create"', $rows[0]);
        $this->assertStringContainsString('row-hidden', $rows[0]);
        $this->assertStringNotContainsString('row-red', $rows[0]);
    }

    public function test_header_row_cells_and_every_colspan_agree(): void
    {
        $filled = $this->render([$this->tx(1, 1000, '2026-09-18 10:00:00')]);
        preg_match('#<thead>(.*?)</thead>#s', $filled, $thead);
        $thCount = substr_count($thead[1], '<th ');
        $tdCount = substr_count($this->rows($filled)[0], '<td ');

        $this->assertSame(7, $thCount, 'колонок «Деньги» — 7');
        $this->assertSame($thCount, $tdCount, 'ячеек в строке столько же, сколько заголовков');

        $empty = $this->render([]);
        preg_match_all('#colspan="(\d+)"#', $empty, $spans);
        $this->assertSame(['7', '7', '7'], $spans[1],
            'пустые поступления, разделитель и заглушка расходов растянуты на все колонки');
        $this->assertStringContainsString('Нет поступлений за период.', $empty);
        $this->assertStringContainsString('Загрузка писем о расходах…', $this->tbodies($empty)['pd3OutMailTbody']);
    }

    public function test_footer_carries_both_income_and_expense_counters(): void
    {
        $html = $this->render([$this->tx(1, 1000, '2026-09-18 10:00:00'), $this->tx(2, 2500, '2026-09-18 11:00:00')]);
        foreach (['pd3SepayTotal', 'pd3SepayLinked', 'pd3SepayUnlinked', 'pd3OutMailTotal', 'pd3OutMailCount'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "футер: {$id}");
        }
        $this->assertMatchesRegularExpression('#id="pd3SepayTotal">3\s?500<#u', $html, 'итог поступлений считает сервер');
    }

    public function test_date_is_html_escaped(): void
    {
        $html = $this->render([$this->tx(9, 1000, '2026-09-18 "10:00"')]);
        $this->assertStringContainsString('data-date="2026-09-18 &quot;10:00&quot;"', $html);
    }
}
