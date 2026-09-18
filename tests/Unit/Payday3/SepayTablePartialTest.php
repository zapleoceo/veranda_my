<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\SepayTransaction;
use PHPUnit\Framework\TestCase;

/**
 * Серверная разметка таблицы SePay (вкладка IN).
 *
 * Строки SePay рисуются в ДВУХ местах: этим шаблоном при первой загрузке
 * и JS-функцией sepayRow (payday3/assets/js/in/renderTables.js) после
 * синка. Кнопка «+» (создать приход в Poster) и число колонок обязаны
 * совпадать — JS-сторону держит tests/js/payday3/renderTables.test.mjs.
 * Здесь — серверная половина того же контракта.
 */
final class SepayTablePartialTest extends TestCase
{
    private const PARTIAL = __DIR__ . '/../../../src/Views/payday3/partials/sepay_table.php';

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

    public function test_every_row_has_income_create_button_with_row_data(): void
    {
        $html = $this->render([$this->tx(62165230, 350000, '2026-09-18 14:05:09')]);

        $rows = $this->rows($html);
        $this->assertCount(1, $rows);
        $this->assertMatchesRegularExpression(
            '#<button type="button" class="pd3-row-create" title="Создать приход в Poster на эту сумму"'
            . ' data-tx-type="1" data-amount="350000" data-date="2026-09-18 14:05:09">\+</button>#',
            $rows[0],
            'кнопка «+» в IN создаёт ПРИХОД (data-tx-type=1) на сумму и время строки'
        );
    }

    public function test_hidden_rows_also_carry_button_but_are_not_row_red(): void
    {
        // Видимость кнопки решает CSS: только tr.row-red. Скрытые строки
        // получают row-hidden — кнопка в них есть, но не показывается.
        $html = $this->render([], [$this->tx(5, 1000, '2026-09-18 10:00:00')]);

        $rows = $this->rows($html);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('class="pd3-row-create"', $rows[0]);
        $this->assertStringContainsString('row-hidden', $rows[0]);
        $this->assertStringNotContainsString('row-red', $rows[0]);
    }

    public function test_header_row_cells_and_empty_colspan_agree(): void
    {
        $filled = $this->render([$this->tx(1, 1000, '2026-09-18 10:00:00')]);
        preg_match('#<thead>(.*?)</thead>#s', $filled, $thead);
        $thCount = substr_count($thead[1], '<th ');
        $tdCount = substr_count($this->rows($filled)[0], '<td ');

        $this->assertSame(7, $thCount, 'колонок SePay стало 7 — добавлена «+»');
        $this->assertSame($thCount, $tdCount, 'ячеек в строке столько же, сколько заголовков');

        $empty = $this->render([]);
        $this->assertStringContainsString(
            '<td colspan="' . $thCount . '">Нет банковских транзакций за период.</td>',
            $empty,
            'пустая строка растягивается на все колонки'
        );
    }

    public function test_date_is_html_escaped(): void
    {
        $html = $this->render([$this->tx(9, 1000, '2026-09-18 "10:00"')]);
        $this->assertStringContainsString('data-date="2026-09-18 &quot;10:00&quot;"', $html);
    }
}
