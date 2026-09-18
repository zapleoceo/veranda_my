<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * Единая страница payday3: IN и OUT больше не вкладки.
 *
 *   слева   «Деньги» — поступления и расходы в одной таблице;
 *   середина одна колонка управления связями;
 *   справа  чеки Poster, под ними транзакции Poster.
 *
 * Смоук-рендер всей страницы (content.php со всеми partials) на пустых
 * данных: ловит фатальные ошибки шаблонов и держит каркас, от которого
 * зависит JS (id таблиц, две SVG-подложки для линий, одна панель связей).
 */
final class SinglePageLayoutTest extends TestCase
{
    private static ?string $html = null;

    /**
     * Rendered once per class: poster_table.php / totals.php declare
     * top-level constants, so a second require in the same process would
     * warn "already defined" (harmless in production — one render per request).
     */
    private function renderPage(): string
    {
        return self::$html ??= self::render();
    }

    private static function render(): string
    {
        $range            = DateRange::of('2026-09-18', '2026-09-18');
        $sepayOpen        = [];
        $sepayHidden      = [];
        $poster           = [];
        $links            = [];
        $linksJson        = [];
        $linkBySepay      = [];
        $linkByPoster     = [];
        $rowStateBySepay  = [];
        $rowStateByPoster = [];
        ob_start();
        require __DIR__ . '/../../../src/Views/payday3/content.php';
        return (string)ob_get_clean();
    }

    public function test_one_graph_with_bank_left_and_two_poster_tables_right(): void
    {
        $html = $this->renderPage();

        $this->assertSame(1, substr_count($html, 'class="pd3-graph__grid"'), 'одна сетка вместо двух секций IN/OUT');
        $this->assertStringNotContainsString('pd3-section--', $html, 'секций-вкладок больше нет');

        $bank    = strpos($html, 'id="pd3BankTable"');
        $mid     = strpos($html, 'class="pd3-mid"');
        $right   = strpos($html, 'id="pd3RightColumn"');
        $checks  = strpos($html, 'id="pd3PosterTable"');
        $finance = strpos($html, 'id="pd3OutFinanceTable"');
        foreach (compact('bank', 'mid', 'right', 'checks', 'finance') as $name => $pos) {
            $this->assertNotFalse($pos, "на странице есть: {$name}");
        }
        $this->assertTrue($bank < $mid && $mid < $right, 'слева «Деньги», в середине связи, справа Poster');
        $this->assertTrue($right < $checks && $checks < $finance, 'справа чеки сверху, транзакции Poster под ними');
    }

    public function test_one_link_panel_and_two_line_layers(): void
    {
        $html = $this->renderPage();

        foreach (['pd3LinkMakeBtn', 'pd3LinkAutoBtn', 'pd3LinkClearBtn', 'pd3HideLinkedBtn', 'pd3ModeToggle'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), "единственная кнопка {$id}");
        }
        $this->assertSame(1, substr_count($html, 'class="pd3-mid"'), 'одна средняя колонка');
        $this->assertStringNotContainsString('pd3OutLinkAutoBtn', $html, 'отдельных OUT-кнопок связи нет');

        $this->assertStringContainsString('id="pd3LineLayer"', $html, 'линии поступления ↔ чеки');
        $this->assertStringContainsString('id="pd3OutLineLayer"', $html, 'линии расходы ↔ транзакции');
    }

    public function test_every_element_id_is_unique(): void
    {
        preg_match_all('#\sid="([^"]+)"#', $this->renderPage(), $m);
        $dupes = array_keys(array_filter(array_count_values($m[1]), static fn(int $n) => $n > 1));
        $this->assertSame([], $dupes, 'после слияния вкладок id не должны повторяться');
    }
}
