<?php

declare(strict_types=1);

namespace App\Order\Http;

use App\Order\Infrastructure\Csrf;
use App\Order\Infrastructure\I18n;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin GET handler for /neworder. Renders the standalone (no
 * sidebar) order page — mobile-first dark/gold UI. The JS bootstrap
 * pulls menu / locations / open-checks on demand; this controller
 * only ships the static HTML shell + CSRF token + language slice +
 * asset versions.
 */
final class NewOrderController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $csrfToken   = Csrf::token();
        $root = dirname(__DIR__, 3);
        $cssVersion = self::assetVersion([
            $root . '/assets/css/common.css',
            $root . '/neworder/assets/css/order.css',
        ]);
        // The bootstrap forwards this version to every imported module.
        $jsVersion = self::assetVersion(array_merge(
            glob($root . '/neworder/assets/js/*.js') ?: [],
            glob($root . '/neworder/assets/js/ui/*.js') ?: [],
        ));

        $requested   = is_string($request->getQueryParams()['lang'] ?? null) ? $request->getQueryParams()['lang'] : null;
        $cookieLang  = $_COOKIE[I18n::COOKIE_NAME] ?? null;
        $accept      = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $lang        = I18n::resolve($requested, is_string($cookieLang) ? $cookieLang : null, $accept);
        $t           = I18n::strings($lang);

        // Persist explicit `?lang=` choices to the cookie so subsequent
        // requests honour the operator's pick without the query string.
        if ($requested !== null && in_array(strtolower($requested), I18n::SUPPORTED, true)) {
            setcookie(
                I18n::COOKIE_NAME,
                strtolower($requested),
                [
                    'expires'  => time() + I18n::COOKIE_TTL,
                    'path'     => '/neworder',
                    'samesite' => 'Lax',
                    'httponly' => false,
                ],
            );
        }

        ob_start();
        require __DIR__ . '/../../Views/order/index.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @param list<string> $files */
    private static function assetVersion(array $files): string
    {
        sort($files);
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update_file($hash, $file);
        }
        return substr(hash_final($hash), 0, 16);
    }
}
