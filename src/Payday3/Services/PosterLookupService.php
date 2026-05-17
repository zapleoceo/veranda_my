<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterLookupServiceInterface;

/**
 * Read-only Poster lookups. Each method is a single API call that
 * normalises the response to an array (so the front-end never has
 * to handle `false` or `null`).
 */
final class PosterLookupService implements PosterLookupServiceInterface
{
    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    public function employees(): array
    {
        $rows = $this->poster->client()->request('access.getEmployees', []);
        return is_array($rows) ? $rows : [];
    }

    public function financeAccounts(): array
    {
        $rows = $this->poster->client()->request('finance.getAccounts', []);
        return is_array($rows) ? $rows : [];
    }

    public function financeCategories(): array
    {
        $rows = $this->poster->client()->request('finance.getCategories', []);
        return is_array($rows) ? $rows : [];
    }
}
