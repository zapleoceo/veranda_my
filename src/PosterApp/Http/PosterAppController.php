<?php

declare(strict_types=1);

namespace App\PosterApp\Http;

use App\PosterApp\Infrastructure\PosterAppConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /poster-app/  — serves the widget HTML that Poster loads
 * inside the POS iframe.
 *
 * Crucially we DON'T send X-Frame-Options:DENY here — the page is
 * MEANT to be framed by Poster. Slim's default middleware doesn't
 * add one, and our own Session::configure doesn't either, so a
 * normal response works. We do, however, set Content-Security-Policy
 * frame-ancestors to LIMIT framing to Poster + ourselves.
 */
final class PosterAppController
{
    public function __construct(private readonly PosterAppConfig $cfg) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $appId      = $this->cfg->applicationId();
        $cssVersion = self::fileMtime(__DIR__ . '/../../../poster-app/assets/css/widget.css');
        $jsVersion  = self::fileMtime(__DIR__ . '/../../../poster-app/assets/js/widget.js');

        ob_start();
        require __DIR__ . '/../../Views/poster_app/index.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow')
            // Allow Poster (any *.joinposter.com) + our own host to frame us.
            ->withHeader(
                'Content-Security-Policy',
                "frame-ancestors 'self' https://*.joinposter.com https://joinposter.com"
            );
    }

    private static function fileMtime(string $abs): string
    {
        $t = @filemtime($abs);
        return $t !== false ? (string)$t : '1';
    }
}
