<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Classes\PosterAPI;

/**
 * Single source of `new PosterAPI(token)`. Every Poster-touching
 * service injects this instead of constructing the client inline.
 * Lets tests swap in a fake without monkey-patching globals, and
 * keeps the token-resolution logic in one place.
 */
interface PosterApiProviderInterface
{
    public function client(): PosterAPI;
}
