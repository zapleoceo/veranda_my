<?php

declare(strict_types=1);

namespace App\Order\Http;

use App\Order\Infrastructure\Csrf;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin GET handler for /neworder. Renders the standalone (no
 * sidebar) order page — mobile-first dark/gold UI. The JS bootstrap
 * pulls menu / locations / open-checks on demand; this controller
 * only ships the static HTML shell + CSRF token + asset versions.
 */
final class NewOrderController
{
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $csrfToken   = Csrf::token();
        $cssVersion  = self::fileMtime(__DIR__ . '/../../../neworder/assets/css/order.css');
        $jsVersion   = self::fileMtime(__DIR__ . '/../../../neworder/assets/js/index.js');

        ob_start();
        require __DIR__ . '/../../Views/order/index.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function fileMtime(string $abs): string
    {
        $t = @filemtime($abs);
        return $t !== false ? (string)$t : '1';
    }
}
