<?php

declare(strict_types=1);

namespace App\Payday3\Http;

use App\Infrastructure\Permissions;
use App\Infrastructure\Session;
use App\Infrastructure\SessionCsrf;
use App\Middleware\CsrfGuard;
use App\Payday3\Domain\DateRange;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Thin GET handler for /payday3. All it does is:
 *   1. Parse the date range from query.
 *   2. Ask the assembler for view data.
 *   3. Render the content partial wrapped in layout.php.
 *
 * Every AJAX endpoint lives in its own single-action class under
 * src/Payday3/Http/Actions/ — this controller stays one screen long.
 */
final class Payday3Controller
{
    public function __construct(private readonly PageDataAssembler $assembler) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Strict (fail-closed) check — the route gate covers this too,
        // but the controller must not be permissive on its own.
        if (!Permissions::can('payday')) {
            return Permissions::denyHtml($response);
        }

        // Per-session CSRF token for /payday3/api (CsrfGuard). Token
        // creation may write the session — release the lock right after
        // so parallel XHRs from the freshly loaded page don't queue.
        $csrfToken = SessionCsrf::token(CsrfGuard::PAYDAY3);
        Session::close();

        $range = DateRange::fromQuery($request->getQueryParams());
        $data  = $this->assembler->assemble($range);

        // Wrap the content partial in the shared sidebar layout.
        $pageTitle    = 'PayDay3';
        $currentPath  = '/payday3';
        $headExtra    = '<link rel="stylesheet" href="/payday3/assets/css/payday3.css?v=' . self::assetVersion() . '">';

        $viewVars = $data + ['range' => $range, 'csrfToken' => $csrfToken];
        ob_start();
        // Extract DTOs for the partial.
        extract($viewVars, EXTR_SKIP);
        require __DIR__ . '/../../Views/payday3/content.php';
        $content = (string)ob_get_clean();

        ob_start();
        require __DIR__ . '/../../Views/layout.php';
        $html = (string)ob_get_clean();

        $response->getBody()->write($html);
        // Anti-clickjacking: the page drives money operations, never let
        // a foreign site frame it.
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Content-Security-Policy', "frame-ancestors 'self'");
    }

    private static function assetVersion(): string
    {
        $f = __DIR__ . '/../../../payday3/assets/css/payday3.css';
        $t = @filemtime($f);
        return $t !== false ? (string)$t : '1';
    }
}
