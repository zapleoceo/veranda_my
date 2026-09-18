<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\DateRange;
use PHPUnit\Framework\TestCase;

/**
 * Панель payday3: выбор дня и кнопки ‹ / › (день назад / вперёд).
 * Поведение кнопок держит tests/js/payday3/dateForm.test.mjs; здесь —
 * что сервер их вообще рисует и в правильном порядке вокруг поля даты.
 */
final class ToolbarPartialTest extends TestCase
{
    private function render(string $day): string
    {
        $range = DateRange::of($day, $day);
        ob_start();
        require __DIR__ . '/../../../src/Views/payday3/partials/toolbar.php';
        return (string)ob_get_clean();
    }

    public function test_day_step_buttons_surround_the_date_input(): void
    {
        $html = $this->render('2026-09-18');
        preg_match('#<form[^>]*id="pd3DateForm".*?</form>#s', $html, $form);
        $this->assertNotEmpty($form, 'форма выбора даты на месте');

        $prev  = strpos($form[0], 'data-date-step="-1"');
        $input = strpos($form[0], 'name="dateFrom"');
        $next  = strpos($form[0], 'data-date-step="1"');
        $this->assertNotFalse($prev, 'кнопка «предыдущий день»');
        $this->assertNotFalse($next, 'кнопка «следующий день»');
        $this->assertTrue($prev < $input && $input < $next, 'порядок: ‹ [дата] ›');

        $this->assertStringContainsString('aria-label="Предыдущий день"', $form[0]);
        $this->assertStringContainsString('aria-label="Следующий день"', $form[0]);
        $this->assertSame(2, substr_count($form[0], 'type="button" class="pd3-icon-btn pd3-icon-btn--small pd3-date-step"'),
            'обе кнопки type=button — клик не должен отправлять форму сам по себе');
    }

    public function test_tabs_are_gone_and_create_transaction_button_took_their_place(): void
    {
        $html = $this->render('2026-09-18');
        $this->assertStringNotContainsString('data-tab=', $html, 'вкладок IN/OUT больше нет — всё на одной странице');
        $this->assertStringNotContainsString('pd3-tabs', $html);
        $this->assertMatchesRegularExpression(
            '#<button type="button" class="pd3-btn pd3-btn--sm" id="pd3CreateTxBtn"[^>]*>\+ транзакция</button>#u',
            $html,
            'кнопка «+ транзакция» открывает модалку создания транзакции'
        );
    }

    public function test_selected_day_is_in_visible_and_hidden_fields(): void
    {
        $html = $this->render('2026-09-18');
        $this->assertStringContainsString('name="dateFrom" class="pd3-date" value="2026-09-18"', $html);
        $this->assertStringContainsString('name="dateTo" class="pd3-date--to" value="2026-09-18"', $html);
    }
}
