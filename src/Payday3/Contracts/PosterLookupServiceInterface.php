<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

/**
 * Read-only "lookup" endpoints around the Poster API. Tiny, no
 * business logic — just thin wrappers around `request(...)` so the
 * UI can populate dropdowns (employees, accounts, categories) from
 * one HTTP call each.
 */
interface PosterLookupServiceInterface
{
    /** access.getEmployees */
    public function employees(): array;

    /** finance.getAccounts */
    public function financeAccounts(): array;

    /** finance.getCategories */
    public function financeCategories(): array;
}
