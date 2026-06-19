<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Builds the Google OAuth 2.0 authorize URL. Single source of truth for the
 * consent-screen redirect — used by the staff login page and the blogger
 * cabinet, so the client_id / scope / redirect_uri are configured in one
 * place. The caller is responsible for stashing its own `auth_next`.
 */
final class GoogleOAuth
{
    public static function authorizeUrl(): string
    {
        $params = [
            'client_id'     => Config::require('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => Config::require('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope'         => 'email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
}
