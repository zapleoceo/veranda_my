<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Database;
use App\Middleware\AuthMiddleware;
use App\Services\UserPermissionsService;
use DI\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ManagerOrderAccessTest extends TestCase
{
    protected function setUp(): void { $_SESSION = []; }
    protected function tearDown(): void { $_SESSION = []; }

    public static function endpoints(): array
    {
        return [
            ['GET', '/neworder'], ['GET', '/neworder/'],
            ['GET', '/neworder/api/menu'], ['GET', '/neworder/api/locations'],
            ['GET', '/neworder/api/open-checks'],
            ['POST', '/neworder/api/orders'], ['POST', '/neworder/api/orders/append'],
        ];
    }

    private function app(array $permissions = []): \Slim\App
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetch')->willReturn(['permissions_json' => json_encode($permissions)]);
        $db = $this->createMock(Database::class);
        $db->method('t')->willReturn('users');
        $db->method('query')->willReturn($statement);
        $container = new Container();
        $container->set(AuthMiddleware::class, new AuthMiddleware(new ResponseFactory(), new UserPermissionsService($db)));
        $app = AppFactory::create(container: $container);
        require dirname(__DIR__, 2) . '/src/Bootstrap/routes.php';
        // Keep actual route middleware; replace actions so tests cannot call Poster.
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $route->setCallable(fn ($request, $response) => $response->withStatus(204));
        }
        return $app;
    }

    #[DataProvider('endpoints')]
    public function test_guest_cannot_reach_any_manager_action(string $method, string $path): void
    {
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest($method, $path));
        $api = str_contains($path, '/api/');
        self::assertSame($api ? 401 : 302, $response->getStatusCode());
        self::assertSame($api ? '/neworder/' : $path, $_SESSION['auth_next']);
        if ($api) self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[DataProvider('endpoints')]
    public function test_revoked_permission_overrides_fresh_legacy_session(string $method, string $path): void
    {
        $_SESSION = ['user_email' => 'manager@example.com', 'user_permissions' => ['admin' => true, 'neworder' => true], 'user_permissions_loaded_at' => time()];
        $response = $this->app()->handle((new ServerRequestFactory())->createServerRequest($method, $path));
        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($_SESSION['user_permissions']['neworder']);
    }

    #[DataProvider('endpoints')]
    public function test_granted_user_reaches_actions_with_valid_csrf(string $method, string $path): void
    {
        $_SESSION = ['user_email' => 'manager@example.com', 'neworder_csrf' => str_repeat('a', 64)];
        $request = (new ServerRequestFactory())->createServerRequest($method, 'https://veranda.my' . $path)
            ->withHeader('Origin', 'https://veranda.my')->withHeader('X-Csrf-Token', str_repeat('a', 64));
        self::assertSame(204, $this->app(['neworder' => true])->handle($request)->getStatusCode());
    }

    public function test_admin_is_allowed_and_mutations_still_require_csrf(): void
    {
        $_SESSION['user_email'] = 'admin@example.com';
        $app = $this->app(['admin' => true]);
        $factory = new ServerRequestFactory();
        self::assertSame(204, $app->handle($factory->createServerRequest('GET', '/neworder/'))->getStatusCode());
        self::assertSame(403, $app->handle($factory->createServerRequest('POST', '/neworder/api/orders'))->getStatusCode());
    }

    public function test_customer_checkout_remains_public(): void
    {
        $app = $this->app();
        foreach (['/onlineorder/', '/onlineorder/api/menu'] as $path) {
            self::assertSame(204, $app->handle((new ServerRequestFactory())->createServerRequest('GET', $path))->getStatusCode());
        }
    }
}
