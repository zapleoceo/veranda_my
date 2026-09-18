<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Middleware\CsrfGuard;
use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\PosterRepositoryInterface;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Http\PageDataAssembler;
use App\Payday3\Http\Payday3Controller;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Страница /payday3:
 *   - право `payday` проверяется строго (раньше при отсутствии массива
 *     прав контроллер пропускал — fail-open);
 *   - в bootstrap-JSON уходит настоящий CSRF-токен (раньше — пустой
 *     payday2_csrf, и api.js вообще не слал заголовок);
 *   - анти-кликджекинг заголовки.
 * Плюс структурные проверки схемы/IMAP/статики из аудита.
 */
final class Payday3PageSecurityTest extends TestCase
{
    protected function setUp(): void    { $_SESSION = []; }
    protected function tearDown(): void { $_SESSION = []; }

    private function controller(): Payday3Controller
    {
        $sepay  = $this->createMock(SepayRepositoryInterface::class);
        $poster = $this->createMock(PosterRepositoryInterface::class);
        $links  = $this->createMock(LinkRepositoryInterface::class);
        foreach ([$sepay, $poster, $links] as $m) {
            foreach (['listOpenInRange', 'listHiddenInRange', 'listClosedInRange', 'listInRange'] as $fn) {
                if (method_exists($m, $fn)) $m->method($fn)->willReturn([]);
            }
        }
        return new Payday3Controller(new PageDataAssembler($sepay, $poster, $links));
    }

    private function get(): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/payday3/')
            ->withQueryParams(['date' => '2026-09-18']);
        return $this->controller()->index($req, new Response());
    }

    public function test_missing_permissions_array_is_denied(): void
    {
        $this->assertSame(403, $this->get()->getStatusCode());
    }

    public function test_page_ships_csrf_token_and_frame_headers(): void
    {
        $_SESSION['user_permissions'] = ['payday' => 1];
        $_SESSION['user_email']       = 'op@example.com';
        $res  = $this->get();
        $html = (string)$res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('SAMEORIGIN', $res->getHeaderLine('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'self'", $res->getHeaderLine('Content-Security-Policy'));

        $token = $_SESSION[CsrfGuard::PAYDAY3] ?? '';
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertTrue(
            preg_match('#<script type="application/json" id="pd3-bootstrap">\s*(.*?)\s*</script>#s', $html, $m) === 1,
            'bootstrap JSON present'
        );
        $boot = json_decode($m[1], true);
        $this->assertSame($token, $boot['csrf']);
    }

    // ─── structural checks ─────────────────────────────────────────────

    private static function src(string $rel): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/src/' . $rel);
    }

    public function test_payday_tables_are_created_by_bootstrap(): void
    {
        $db = self::src('classes/Database.php');
        foreach (['out_links', 'mail_hidden', 'sepay_hidden', 'payday_audit_log'] as $t) {
            $this->assertStringContainsString("\$this->t('{$t}')", $db, "{$t} must be created in createPaydayTables()");
        }
        $this->assertStringContainsString('UNIQUE KEY uq_out_link_pair (mail_uid, finance_id)', $db);
        $this->assertStringContainsString('KEY idx_out_links_date_to (date_to)', $db);
    }

    // php-imap (c-client) не шлёт SNI — Gmail без SNI отдаёт self-signed
    // сертификат, поэтому проверка сертификата на проде невозможна.
    public function test_imap_skips_cert_check_because_c_client_has_no_sni(): void
    {
        $src = self::src('Payday3/Services/MailImapService.php');
        $this->assertStringContainsString("'{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX'", $src);
    }

    public function test_payday3_api_group_has_csrf_guard_and_no_out_data(): void
    {
        $routes = self::src('Bootstrap/routes.php');
        $this->assertStringContainsString('CsrfGuard::payday3()', $routes);
        $this->assertStringNotContainsString("'/out/data'", $routes);
        $this->assertFileDoesNotExist(dirname(__DIR__, 3) . '/src/Payday3/Http/Actions/OutDataAction.php');
    }

    public function test_static_controller_containment_uses_trailing_separator(): void
    {
        $this->assertStringContainsString('DIRECTORY_SEPARATOR;', self::src('Controllers/StaticController.php'));
        $this->assertStringContainsString('str_starts_with($resolved, $baseWithSep)', self::src('Controllers/StaticController.php'));
    }
}
