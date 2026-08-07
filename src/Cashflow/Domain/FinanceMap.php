<?php

declare(strict_types=1);

namespace App\Cashflow\Domain;

/**
 * The P&L layout: ordered columns (as in Excel «Лист1») and which Poster
 * finance categories feed each expense/income column.
 *
 * Single source of truth for the category → column mapping. Reassigning a
 * category is a one-line change here (a DB-backed editable map is a later
 * step, per TZ §5.1 report_finance_map).
 *
 * Kinds:
 *   revenue — filled by RevenueService from dash.getCategoriesSales (food/hookah)
 *   income  — summed from finance.getTransactions, shown as positive income
 *   expense — summed from finance.getTransactions, shown as positive cost
 *   profit  — computed = (food + hookah + income) − Σ(expense columns)
 *
 * Category assignments derived from live Poster + reconciliation against the
 * Excel monthly ИТОГО on 2026-08-07. Exact matches to the đồng: Аренда+ком
 * (8,9,10,11,12), Комиссия кальяны (17), Музыканты (20), Мероприятия (16).
 * Fuzzy columns (Зарплата, Закупки, Расходы всякие, маркетинг/бюрократия)
 * differ a few M from the old Excel where numbers were hand-adjusted — the
 * report shows Poster truth.
 */
final class FinanceMap
{
    /**
     * @return list<array{key:string,label:string,short:string,kind:string,cats:list<int>}>
     */
    public static function columns(): array
    {
        return [
            ['key' => 'food',       'label' => 'Продажи еды/напитков',        'short' => 'Еда/напитки',    'kind' => 'revenue', 'cats' => []],
            ['key' => 'hookah',     'label' => 'Продажи кальянов',            'short' => 'Кальяны',        'kind' => 'revenue', 'cats' => []],
            ['key' => 'events',     'label' => 'Приходы за мероприятия/сцену', 'short' => 'Мероприятия',    'kind' => 'income',  'cats' => [16]],
            ['key' => 'commission', 'label' => 'Комиссия за кальяны',         'short' => 'Комиссия кальян','kind' => 'expense', 'cats' => [17]],
            ['key' => 'salary',     'label' => 'Зарплата',                    'short' => 'Зарплата',       'kind' => 'expense', 'cats' => [6, 15]],
            ['key' => 'purchases',  'label' => 'Закупки продуктов',           'short' => 'Закупки',        'kind' => 'expense', 'cats' => [3, 19]],
            ['key' => 'musicians',  'label' => 'Расходы на музыкантов',       'short' => 'Музыканты',      'kind' => 'expense', 'cats' => [20]],
            ['key' => 'misc',       'label' => 'Расходы всякие',              'short' => 'Всякие',         'kind' => 'expense', 'cats' => [13, 18, 5]],
            ['key' => 'rent',       'label' => 'Аренда+коммуналка',           'short' => 'Аренда+ком',     'kind' => 'expense', 'cats' => [8, 9, 10, 11, 12]],
            ['key' => 'marketing',  'label' => 'маркетинг/бюрократия',        'short' => 'Маркет/бюр',     'kind' => 'expense', 'cats' => [7, 21, 24]],
            ['key' => 'taxes',      'label' => 'Налоги',                      'short' => 'Налоги',         'kind' => 'expense', 'cats' => []],
            ['key' => 'profit',     'label' => 'Прибыль',                     'short' => 'Прибыль',        'kind' => 'profit',  'cats' => []],
        ];
    }

    /**
     * Finance categories deliberately kept OUT of the P&L:
     *   1  — межсчётные переводы; 2 — Кассовые смены (инкассация выручки);
     *   4  — actualization (безналичная переоценка склада, не денежный расход;
     *        Маша её в «Всякие» не включает — исключение даёт точное совпадение
     *        июньских «Всяких» с Excel до донга);
     *   14 — e-wallets (та же выручка); 22 — Инвесторы (ниже линии, дивиденды);
     *   23 — Баня (отдельный учёт).
     */
    public const EXCLUDED_CATS = [1, 2, 4, 14, 22, 23];

    /** Expense/income column keys (everything summed from finance). */
    public static function financeColumnKeys(): array
    {
        $keys = [];
        foreach (self::columns() as $c) {
            if ($c['kind'] === 'expense' || $c['kind'] === 'income') {
                $keys[] = $c['key'];
            }
        }
        return $keys;
    }

    /** Expense column keys only (used to sum the deductions for profit). */
    public static function expenseColumnKeys(): array
    {
        $keys = [];
        foreach (self::columns() as $c) {
            if ($c['kind'] === 'expense') {
                $keys[] = $c['key'];
            }
        }
        return $keys;
    }
}
