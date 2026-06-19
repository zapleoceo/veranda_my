<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Bloggers\Services\BloggerService;
use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\ReturnPath;
use App\Infrastructure\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CallbackController
{
    public function __construct(
        private readonly Database $db,
        private readonly BloggerService $bloggerService,
    ) {}

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

        $email    = (string) ($userData['email'] ?? '');
        $verified = filter_var($userData['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $authNext = (string) ($_SESSION['auth_next'] ?? '');
        unset($_SESSION['auth_next']);

        // If the login was initiated from /bloggers, check the blogger group
        // first — even if the person is also staff (owner testing their own
        // cabinet should land in the cabinet, not the admin panel). Blogger
        // identity is bound to a Google-verified email only.
        if ($verified && str_starts_with($authNext, '/bloggers')) {
            $resp = $this->_bloggerLogin($email, $userData, $response);
            if ($resp !== null) {
                return $resp;
            }
            // Not a blogger — fall through to staff check below.
        }

        $users = $this->db->t('users');
        $user  = $this->db->query(
            "SELECT * FROM {$users} WHERE email = ? AND is_active = 1",
            [$email]
        )->fetch();

        if (!$user) {
            // Not staff — maybe a blogger arriving via /login (not /bloggers).
            if ($verified) {
                $resp = $this->_bloggerLogin($email, $userData, $response);
                if ($resp !== null) {
                    return $resp;
                }
            }
            return $response->withHeader('Location', '/login?error=access')->withStatus(302);
        }

        $_SESSION['user_email'] = $email;
        $_SESSION['user_name']  = trim((string) ($userData['name'] ?? $email));
        $pic = (string) ($userData['picture'] ?? '');
        if ($pic !== '' && str_starts_with($pic, 'https://')) {
            $_SESSION['user_avatar'] = $pic;
        }

        // Re-validate the stashed return path at the redirect site (defence in
        // depth — never trust auth_next blindly). Blogger paths are not for staff.
        $next = '/admin';
        if ($authNext !== '' && !str_starts_with($authNext, '/bloggers') && ReturnPath::isSafe($authNext)) {
            $next = $authNext;
        }

        return $response->withHeader('Location', $next)->withStatus(302);
    }

    /**
     * Bind an active blogger to a separate session realm (blogger_client_id,
     * NO user_email → cannot reach /admin/*). Returns the redirect to the
     * cabinet, or null when the email is not an active blogger.
     */
    private function _bloggerLogin(string $email, array $userData, ResponseInterface $response): ?ResponseInterface
    {
        $bloggerId = $this->bloggerService->findByEmail($email);
        if ($bloggerId <= 0) {
            return null;
        }
        $_SESSION['blogger_client_id'] = $bloggerId;
        $_SESSION['blogger_email']     = $email;
        $_SESSION['blogger_name']      = trim((string) ($userData['name'] ?? $email));
        return $response->withHeader('Location', '/bloggers')->withStatus(302);
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
