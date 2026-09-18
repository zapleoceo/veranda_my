<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Builds the Google OAuth 2.0 authorize URL. Single source of truth for the
 * consent-screen redirect — used by the staff login page and the blogger
 * cabinet, so the client_id / scope / redirect_uri are configured in one
 * place. The caller is responsible for stashing its own `auth_next`.
 *
 * Login-CSRF defence: every authorize URL carries a random `state` that is
 * remembered in the session; CallbackController::handle() accepts the code
 * only when consumeState() recognises the echoed value. A small ring of
 * recent states is kept so two login tabs opened side by side both work.
 */
final class GoogleOAuth
{
    private const STATE_KEY = 'oauth_states';
    private const STATE_TTL = 1800;   // 30 min to finish the consent screen
    private const STATE_MAX = 5;

    public static function authorizeUrl(): string
    {
        $params = [
            'client_id'     => Config::require('GOOGLE_CLIENT_ID'),
            'redirect_uri'  => Config::require('GOOGLE_REDIRECT_URI'),
            'response_type' => 'code',
            'scope'         => 'email profile',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
            'state'         => self::issueState(),
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /** Create a fresh state token and remember it in the session. */
    public static function issueState(): string
    {
        Session::start();
        $state  = bin2hex(random_bytes(16));
        $states = self::liveStates();
        $states[$state] = time();
        if (count($states) > self::STATE_MAX) {
            $states = array_slice($states, -self::STATE_MAX, null, true);
        }
        $_SESSION[self::STATE_KEY] = $states;
        return $state;
    }

    /** One-shot check: true when $state was issued to this session and is still fresh. */
    public static function consumeState(string $state): bool
    {
        Session::start();
        $states = self::liveStates();
        $ok = false;
        if ($state !== '') {
            foreach (array_keys($states) as $known) {
                if (hash_equals((string)$known, $state)) {
                    $ok = true;
                    unset($states[$known]);
                    break;
                }
            }
        }
        $_SESSION[self::STATE_KEY] = $states;
        return $ok;
    }

    /** @return array<string,int> state → issued-at, expired entries dropped */
    private static function liveStates(): array
    {
        $raw = $_SESSION[self::STATE_KEY] ?? [];
        if (!is_array($raw)) return [];
        $now = time();
        $out = [];
        foreach ($raw as $s => $ts) {
            if (is_string($s) && is_int($ts) && ($now - $ts) <= self::STATE_TTL) {
                $out[$s] = $ts;
            }
        }
        return $out;
    }
}
