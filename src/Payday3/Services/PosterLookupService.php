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
        // Mirror payday2's `?ajax=finance_accounts`: id → name map.
        // The create-transaction modal renders these as <option>s and
        // never needs the rest of the account row (balance, type, etc.)
        $rows = $this->poster->client()->request('finance.getAccounts', []);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $aid  = (int)($r['account_id'] ?? $r['accountId'] ?? 0);
                $name = trim((string)($r['name'] ?? ''));
                if ($aid > 0 && $name !== '') $out[$aid] = $name;
            }
        }
        return $out;
    }

    public function financeCategories(): array
    {
        // Mirror payday2's `?ajax=finance_categories` exactly: a map
        // keyed by category_id, with `name` + `parent_id` per row.
        // Lets the JS render the same hierarchical tree as payday2.
        $rows = $this->poster->client()->request('finance.getCategories', []);
        $out  = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $cid  = (int)($r['category_id'] ?? 0);
                $name = trim((string)($r['name'] ?? ''));
                if ($cid <= 0 || $name === '') continue;
                $out[$cid] = [
                    'name'      => $name,
                    'parent_id' => (int)($r['parent_id'] ?? 0),
                ];
            }
        }
        return $out;
    }
}
