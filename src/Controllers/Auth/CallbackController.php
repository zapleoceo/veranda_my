<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CallbackController
{
    public function __construct(private readonly Database $db) {}

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        Session::start();

        $params = $request->getQueryParams();
        $code   = $params['code'] ?? '';

        if ($code === '') {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $userData = $this->_exchangeCode($code);

        if ($userData === null) {
            return $response->withHeader('Location', '/login?error=oauth')->withStatus(302);
        }

        $email = $userData['email'] ?? '';
        $users = $this->db->t('users');
        $user  = $this->db->query(
            "SELECT * FROM {$users} WHERE email = ? AND is_active = 1",
            [$email]
        )->fetch();

        if (!$user) {
            // Not staff — maybe a blogger. Bloggers live in their own session
            // realm (blogger_client_id, NO user_email) so they can NEVER reach
            // /admin/* (AuthMiddleware authenticates on user_email). They only
            // get the separate /blogger cabinet, scoped to their own data.
            $bloggerId = $this->_findBlogger($email);
            if ($bloggerId > 0) {
                $_SESSION['blogger_client_id'] = $bloggerId;
                $_SESSION['blogger_email']     = $email;
                $_SESSION['blogger_name']      = trim((string) ($userData['name'] ?? $email));

                $next = (string) ($_SESSION['auth_next'] ?? '/blogger');
                unset($_SESSION['auth_next']);
                if (!str_starts_with($next, '/blogger')) {
                    $next = '/blogger';
                }
                return $response->withHeader('Location', $next)->withStatus(302);
            }
            return $response->withHeader('Location', '/login?error=access')->withStatus(302);
        }

        $_SESSION['user_email'] = $email;
        $_SESSION['user_name']  = trim((string) ($userData['name'] ?? $email));
        $pic = (string) ($userData['picture'] ?? '');
        if ($pic !== '' && str_starts_with($pic, 'https://')) {
            $_SESSION['user_avatar'] = $pic;
        }

        $next = $_SESSION['auth_next'] ?? '/admin';
        unset($_SESSION['auth_next']);

        return $response->withHeader('Location', $next)->withStatus(302);
    }

    /** Active blogger's Poster client_id by login email, or 0. */
    private function _findBlogger(string $email): int
    {
        if ($email === '') {
            return 0;
        }
        try {
            $t   = $this->db->t('bloggers');
            $row = $this->db->query(
                "SELECT poster_client_id FROM {$t} WHERE gmail = ? AND is_active = 1 LIMIT 1",
                [$email]
            )->fetch();
            return (int) ($row['poster_client_id'] ?? 0);
        } catch (\Throwable) {
            // bloggers table not created yet → no bloggers exist
            return 0;
        }
    }

    private function _exchangeCode(string $code): array|null
    {
        $tokenData = $this->_post('https://oauth2.googleapis.com/token', [
            'client_id'     => Config::require('GOOGLE_CLIENT_ID'),
            'client_secret' => Config::require('GOOGLE_CLIENT_SECRET'),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => Config::require('GOOGLE_REDIRECT_URI'),
        ]);

        if (empty($tokenData['access_token'])) {
            return null;
        }

        $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . $tokenData['access_token']);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
        $body = curl_exec($ch);
        curl_close($ch);

        $userData = json_decode((string) $body, true);
        return isset($userData['email']) ? $userData : null;
    }

    private function _post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return json_decode((string) $body, true) ?? [];
    }
}
