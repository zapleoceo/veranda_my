<?php

declare(strict_types=1);

namespace App\PosterApp\Infrastructure;

use App\Infrastructure\Config;

/**
 * Single source of truth for the Poster App credentials. Reads from
 * Config (which Bootstrap populates from .env) with $_ENV/getenv as
 * fallbacks. All other PosterApp code asks this class — keeps the
 * "where do credentials live" decision in one place.
 *
 *   POSTER_APP_ID          — the public Application Id from the cabinet
 *   POSTER_APP_SECRET      — the secret. NEVER log this. Used as HMAC
 *                            key for our widget→backend tokens and
 *                            (eventually) for verifying Poster-signed
 *                            install callbacks.
 *
 * `tokenSecret()` derives a separate HMAC key for our local token so
 * a hypothetical Poster-secret leak doesn't immediately let an attacker
 * mint widget sessions for our own backend.
 */
final class PosterAppConfig
{
    public function applicationId(): int
    {
        $raw = Config::get('POSTER_APP_ID')
            ?: ($_ENV['POSTER_APP_ID'] ?? getenv('POSTER_APP_ID') ?: '');
        return is_numeric($raw) ? (int)$raw : 0;
    }

    public function applicationSecret(): string
    {
        return trim((string)(
            Config::get('POSTER_APP_SECRET')
            ?: ($_ENV['POSTER_APP_SECRET'] ?? '')
            ?: (getenv('POSTER_APP_SECRET') ?: '')
        ));
    }

    /** Derived key used to sign widget-issued session tokens. */
    public function tokenSecret(): string
    {
        $base = $this->applicationSecret();
        if ($base === '') {
            // Bootstrap not fully configured — return a deterministic
            // empty marker so token mint/verify both fail closed.
            return '';
        }
        return hash_hmac('sha256', 'neworder.token.v1', $base);
    }
}
