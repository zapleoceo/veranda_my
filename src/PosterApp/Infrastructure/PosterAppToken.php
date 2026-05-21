<?php

declare(strict_types=1);

namespace App\PosterApp\Infrastructure;

/**
 * Tiny stateless session token for the widget.
 *
 * Format:   base64url(JSON payload) . "." . base64url(HMAC-SHA256)
 *           payload = { "uid": 10, "exp": 1716240000 }
 *
 * Why not a real cookie session? Because the widget runs inside a
 * third-party iframe (account.joinposter.com → veranda.my), and
 * cookie-based sessions there need SameSite=None+Secure which would
 * change behaviour for the rest of the site. A signed bearer token
 * in an Authorization header sidesteps the whole cross-site cookie
 * conversation.
 *
 * Lifetime: 12 hours — long enough for a full shift, short enough
 * that a leaked token doesn't grant indefinite access.
 */
final class PosterAppToken
{
    public const TTL_SECONDS = 12 * 3600;

    public function __construct(private readonly PosterAppConfig $cfg) {}

    /** @return string token string */
    public function mint(int $posterUserId): string
    {
        $payload = [
            'uid' => $posterUserId,
            'exp' => time() + self::TTL_SECONDS,
        ];
        $body = $this->b64u(json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}');
        $sig  = $this->b64u(hash_hmac('sha256', $body, $this->cfg->tokenSecret(), true));
        return $body . '.' . $sig;
    }

    /** @return int|null poster_user_id on success, null on bad/expired token */
    public function verify(string $token): ?int
    {
        if ($token === '' || !str_contains($token, '.')) return null;
        [$body, $sig] = explode('.', $token, 2);
        $expected = $this->b64u(hash_hmac('sha256', $body, $this->cfg->tokenSecret(), true));
        if (!hash_equals($expected, $sig)) return null;
        $json = json_decode($this->b64uDecode($body), true);
        if (!is_array($json)) return null;
        $exp = (int)($json['exp'] ?? 0);
        if ($exp <= 0 || $exp < time()) return null;
        $uid = (int)($json['uid'] ?? 0);
        return $uid > 0 ? $uid : null;
    }

    private function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function b64uDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return (string)base64_decode(strtr($s, '-_', '+/'), true);
    }
}
