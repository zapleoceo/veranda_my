<?php

namespace App\Classes;

class Auth {
    private Database $db;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(Database $db, string $clientId = '', string $clientSecret = '', string $redirectUri = '') {
        $this->db = $db;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn(): bool {
        return isset($_SESSION['user_email']);
    }

    /**
     * Redirect to login page if not logged in
     */
    public function requireAuth(): void {
        if (!$this->isLoggedIn()) {
            $next = (string)($_SERVER['REQUEST_URI'] ?? '');
            if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, "\n") || str_contains($next, "\r")) {
                $next = '/dashboard.php';
            }
            $_SESSION['auth_next'] = $next;
            header('Location: /login.php');
            exit;
        }
    }

    /**
     * Get Google Login URL
     */
    public function getGoogleLoginUrl(): string {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'email profile',
            'access_type' => 'online',
            'prompt' => 'select_account'
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Handle Google Callback
     */
    public function handleCallback(string $code): bool {
        // 1. Exchange code for access token
        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $params = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (!isset($data['access_token'])) {
            return false;
        }

        // 2. Get user info
        $userUrl = 'https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . $data['access_token'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $userUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $userResponse = curl_exec($ch);
        curl_close($ch);
        
        $userData = json_decode($userResponse, true);

        if (!isset($userData['email'])) {
            return false;
        }

        $email = $userData['email'];

        // 3. Check if email is allowed in database
        $users = $this->db->t('users');
        $user = $this->db->query("SELECT * FROM {$users} WHERE email = ? AND is_active = 1", [$email])->fetch();

        if ($user) {
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name'] = $userData['name'] ?? $email;
            $pic = (string)($userData['picture'] ?? '');
            if ($pic !== '' && preg_match('/^https:\\/\\//i', $pic)) {
                $_SESSION['user_avatar'] = $pic;
            } else {
                unset($_SESSION['user_avatar']);
            }
            return true;
        }

        return false;
    }

    /**
     * Logout
     */
    public function logout(): void {
        session_destroy();
    }
}
