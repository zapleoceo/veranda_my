<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Which Poster finance transactions may be auto-matched to a bank row.
 *
 * Rule of the day: income with income, expense with expense.
 *   incoming bank row (SePay)   ↔ Poster finance INCOME  (amount > 0)
 *   outgoing bank row (BIDV)    ↔ Poster finance EXPENSE (amount < 0)
 *
 * Never auto-matched (still shown in the table, still linkable by hand):
 *   category 1 «Переводы» — transfers between Poster accounts
 *                (e.g. «Перевод типсов», «Перевод чеков вьетнаской
 *                компании»): internal bookkeeping, no bank movement;
 *   category 2 «Кассовые смены» — shift-close totals («Card payments»,
 *                «Card tips per shift», «Vietnam Company — Card
 *                payments»): the sum of card checks that are already
 *                reconciled one-by-one against the bank via Poster checks
 *                — matching them again would count the money twice.
 */
final class FinanceMatchPolicy
{
    public const CATEGORY_TRANSFERS = 1;
    public const CATEGORY_CASH_SHIFTS = 2;

    public static function isIncomeCandidate(FinanceTransaction $f): bool
    {
        return $f->amount->amount > 0
            && !self::isExcludedCategory($f->categoryId);
    }

    public static function isExpenseCandidate(FinanceTransaction $f): bool
    {
        return $f->amount->amount < 0
            && !self::isExcludedCategory($f->categoryId);
    }

    private static function isExcludedCategory(int $categoryId): bool
    {
        return $categoryId === self::CATEGORY_TRANSFERS
            || $categoryId === self::CATEGORY_CASH_SHIFTS;
    }
}
