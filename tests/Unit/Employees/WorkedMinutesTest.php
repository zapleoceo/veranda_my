<?php

declare(strict_types=1);

namespace Tests\Unit\Employees;

use App\Models\EmployeesModel;
use PHPUnit\Framework\TestCase;

// employees/Model.php лежит вне PSR-4 путей и в проде подключается явным
// require_once из employees/index.php — автозагрузчик его не видит.
require_once __DIR__ . '/../../../employees/Model.php';

/**
 * Отработанные минуты для табеля и расчёта ЗП.
 *
 * Регрессия на выдуманные часы: Poster отдаёт worked_time = null, пока смена
 * не закрыта, а код подставлял middle_time (средняя длительность обслуживания
 * чека В СЕКУНДАХ) и делил её на 60 как минуты. Живые данные 2026-07-30:
 * middle_time = 1294.76 давал 21.58 «отработанных часа». Ошибки не возникало,
 * числа выглядели правдоподобно — поэтому баг и жил в отчёте по зарплате.
 */
final class WorkedMinutesTest extends TestCase
{
    /** Закрытая смена: берём worked_time как есть. */
    public function test_uses_worked_time_when_present(): void
    {
        $this->assertSame(806, EmployeesModel::workedMinutesFromRow([
            'worked_time' => 806,
            'middle_time' => 1814.6376,
        ]));
    }

    /**
     * Открытая смена: worked_time = null. Часов ещё нет — честный 0.
     * Раньше здесь получалось 1295 минут (21.58 ч) из middle_time.
     */
    public function test_open_shift_returns_zero_instead_of_middle_time(): void
    {
        $minutes = EmployeesModel::workedMinutesFromRow([
            'worked_time' => null,
            'middle_time' => 1294.7610333333332,
        ]);

        $this->assertSame(0, $minutes, 'middle_time — это секунды на чек, а не отработанные минуты');
    }

    /** middle_time не должен подставляться, даже когда worked_time отсутствует как ключ. */
    public function test_missing_worked_time_key_still_ignores_middle_time(): void
    {
        $this->assertSame(0, EmployeesModel::workedMinutesFromRow(['middle_time' => 130.70]));
    }

    /** camelCase-вариант поля поддерживается (историческая совместимость). */
    public function test_camel_case_worked_time_is_supported(): void
    {
        $this->assertSame(360, EmployeesModel::workedMinutesFromRow(['workedTime' => 360]));
    }

    /** Строковые числа из JSON приводятся корректно. */
    public function test_numeric_string_is_accepted(): void
    {
        $this->assertSame(734, EmployeesModel::workedMinutesFromRow(['worked_time' => '734']));
    }

    /** Дробное значение округляется, а не отбрасывается. */
    public function test_float_is_rounded(): void
    {
        $this->assertSame(101, EmployeesModel::workedMinutesFromRow(['worked_time' => 100.6]));
    }

    /** Мусор не должен превращаться в часы. */
    public function test_non_numeric_is_zero(): void
    {
        $this->assertSame(0, EmployeesModel::workedMinutesFromRow(['worked_time' => 'н/д']));
        $this->assertSame(0, EmployeesModel::workedMinutesFromRow([]));
    }
}
