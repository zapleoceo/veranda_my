<?php

declare(strict_types=1);

namespace App\OnlineOrder\Http;

use App\OnlineOrder\Infrastructure\I18n;
use App\OnlineOrder\Infrastructure\OnlineOrderConfig;
use App\Order\Infrastructure\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /onlineorder — the public delivery checkout. Ships the HTML
 * shell + CSRF token + language slice + the feature-flag bootstrap
 * (which integrations are live); the JS pulls the menu and drives
 * everything else. Same thin-controller pattern as /neworder.
 */
final class OnlineOrderController
{
    public function __construct(private readonly OnlineOrderConfig $config) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $csrfToken  = Csrf::token();
        $cssVersion = self::fileMtime(__DIR__ . '/../../../onlineorder/assets/css/onlineorder.css');
        $jsVersion  = self::fileMtime(__DIR__ . '/../../../onlineorder/assets/js/index.js');

        $requested  = is_string($request->getQueryParams()['lang'] ?? null) ? $request->getQueryParams()['lang'] : null;
        $cookieLang = $_COOKIE[I18n::COOKIE_NAME] ?? null;
        $accept     = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $lang       = I18n::resolve($requested, is_string($cookieLang) ? $cookieLang : null, $accept);
        $t          = I18n::strings($lang);
        $bootstrap  = $this->config->frontendBootstrap();

        if ($requested !== null && in_array(strtolower($requested), I18n::SUPPORTED, true)) {
            setcookie(I18n::COOKIE_NAME, strtolower($requested), [
                'expires'  => time() + I18n::COOKIE_TTL,
                'path'     => '/onlineorder',
                'samesite' => 'Lax',
                'httponly' => false,
            ]);
        }

        ob_start();
        require __DIR__ . '/../../Views/onlineorder/index.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private static function fileMtime(string $abs): string
    {
        $t = @filemtime($abs);
        return $t !== false ? (string)$t : '1';
    }
}
