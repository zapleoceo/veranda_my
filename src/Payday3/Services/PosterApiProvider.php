<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Classes\PosterAPI;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Lazy-singleton provider for the Poster API client — the single place
 * payday3 does `new PosterAPI`. The token (.env POSTER_API_TOKEN) is
 * injected by the container; an empty token throws RuntimeException on
 * first use, not at wiring time, so pages that never call Poster still
 * render.
 *
 * One client per request ⇒ one reused curl handle (keep-alive to
 * joinposter.com) for every Poster call the request makes.
 */
final class PosterApiProvider implements PosterApiProviderInterface
{
    private ?PosterAPI $client = null;

    public function __construct(private readonly string $token) {}

    public function client(): PosterAPI
    {
        if ($this->client !== null) return $this->client;
        $token = trim($this->token);
        if ($token === '') {
            throw new \RuntimeException('POSTER_API_TOKEN is not configured');
        }
        return $this->client = new PosterAPI($token);
    }
}
