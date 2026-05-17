<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Classes\PosterAPI;
use App\Infrastructure\Config;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Lazy-singleton provider for the Poster API client. Reads
 * POSTER_API_TOKEN from $_ENV (preferred) or the Slim Config loader,
 * throws RuntimeException if neither is set.
 */
final class PosterApiProvider implements PosterApiProviderInterface
{
    private ?PosterAPI $client = null;

    public function client(): PosterAPI
    {
        if ($this->client !== null) return $this->client;
        $token = trim((string)($_ENV['POSTER_API_TOKEN'] ?? Config::get('POSTER_API_TOKEN')));
        if ($token === '') {
            throw new \RuntimeException('POSTER_API_TOKEN is not configured');
        }
        return $this->client = new PosterAPI($token);
    }
}
