<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Bloggers\Services\BloggerService;
use App\Controllers\Auth\CallbackController;
use App\Infrastructure\Database;
use App\Infrastructure\GoogleOAuth;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Google-логин: OAuth `state` (login CSRF) и обязательный email_verified
 * для сотрудников (раньше проверялся только в ветке блогеров — аккаунт
 * Google с неподтверждённым адресом сотрудника входил как этот сотрудник).
 */
final class GoogleOAuthCallbackTest extends TestCase
{
    protected function setUp(): void    { $_SESSION = []; }
    protected function tearDown(): void { $_SESSION = []; }

    public function test_state_is_one_shot_and_bound_to_session(): void
    {
        $state = GoogleOAuth::issueState();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $state);
        $this->assertFalse(GoogleOAuth::consumeState('forged'));
        $this->assertTrue(GoogleOAuth::consumeState($state));
        $this->assertFalse(GoogleOAuth::consumeState($state), 'replay must fail');
        $this->assertFalse(GoogleOAuth::consumeState(''));
    }

    public function test_two_tabs_both_work(): void
    {
        $a = GoogleOAuth::issueState();
        $b = GoogleOAuth::issueState();
        $this->assertTrue(GoogleOAuth::consumeState($b));
        $this->assertTrue(GoogleOAuth::consumeState($a));
    }

    public function test_expired_state_is_rejected(): void
    {
        $state = GoogleOAuth::issueState();
        $_SESSION['oauth_states'][$state] = time() - 3600;
        $this->assertFalse(GoogleOAuth::consumeState($state));
    }

    /** @param array<string,mixed>|null $google userinfo the fake exchange returns */
    private function controller(?array $google, ?array $userRow = null): CallbackController
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetch')->willReturn($userRow ?? false);
        $db = $this->createMock(Database::class);
        $db->method('t')->willReturnArgument(0);
        $db->method('query')->willReturn($stmt);
        $blogger = (new \ReflectionClass(BloggerService::class))->newInstanceWithoutConstructor();

        return new class($db, $blogger, $google) extends CallbackController {
            public function __construct(Database $db, BloggerService $b, private ?array $google)
            {
                parent::__construct($db, $b);
            }
            protected function _exchangeCode(string $code): array|null
            {
                return $this->google;
            }
        };
    }

    private function call(CallbackController $c, array $query): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/auth/callback')->withQueryParams($query);
        return $c->handle($req, new Response());
    }

    public function test_callback_without_valid_state_is_refused_before_code_exchange(): void
    {
        $res = $this->call($this->controller(['email' => 'staff@veranda.my', 'email_verified' => true]),
            ['code' => 'abc', 'state' => 'forged']);
        $this->assertSame('/login?error=state', $res->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user_email', $_SESSION);
    }

    public function test_unverified_email_never_logs_in_staff(): void
    {
        $state = GoogleOAuth::issueState();
        $res = $this->call(
            $this->controller(['email' => 'staff@veranda.my', 'email_verified' => false], ['email' => 'staff@veranda.my']),
            ['code' => 'abc', 'state' => $state],
        );
        $this->assertSame('/login?error=unverified', $res->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user_email', $_SESSION);
    }

    public function test_missing_email_verified_claim_is_treated_as_unverified(): void
    {
        $state = GoogleOAuth::issueState();
        $res = $this->call(
            $this->controller(['email' => 'staff@veranda.my'], ['email' => 'staff@veranda.my']),
            ['code' => 'abc', 'state' => $state],
        );
        $this->assertSame('/login?error=unverified', $res->getHeaderLine('Location'));
    }

    public function test_verified_staff_logs_in(): void
    {
        $state = GoogleOAuth::issueState();
        $_SESSION['auth_next'] = '/payday3/';
        $res = $this->call(
            $this->controller(['email' => 'staff@veranda.my', 'email_verified' => true, 'name' => 'Staff'], ['email' => 'staff@veranda.my']),
            ['code' => 'abc', 'state' => $state],
        );
        $this->assertSame('/payday3/', $res->getHeaderLine('Location'));
        $this->assertSame('staff@veranda.my', $_SESSION['user_email'] ?? null);
    }
}
