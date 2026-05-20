<?php

declare(strict_types=1);

namespace App\Order\Contracts;

use App\Order\Domain\Category;
use App\Order\Domain\MenuItem;

/**
 * Live Poster menu source — no DB caching layer. Each call hits
 * menu.getCategories + menu.getProducts and returns the parsed
 * domain objects. Inactive / hidden products are filtered out.
 */
interface PosterMenuProviderInterface
{
    /** @return Category[] */
    public function fetchCategories(): array;

    /** @return MenuItem[] */
    public function fetchActiveProducts(): array;
}
