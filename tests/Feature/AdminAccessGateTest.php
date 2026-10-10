<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Admin\AccessController;
use App\Infrastructure\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Регрессионный тест на escalation of privilege.
 *
 * Страница /admin/access редактирует права ЛЮБОГО пользователя по email из
 * формы. Роут был закрыт только AuthMiddleware (=просто «залогинен»), а
 * проверки права `admin` в контроллере не было. Итог: сотрудник с одним лишь
 * `dashboard` отправлял save_user_permissions с perm_email=<свой> и
 * perm_admin=1 — и становился админом со всей админкой в придачу.
 *
 * Тесты ниже держат гейт на месте: без права `admin` страница обязана
 * отдавать 403 и НЕ ходить в базу.
 */
final class AdminAccessGateTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function callIndex(Database $db): Response
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/access');
        return (new AccessController($db))->index($request, new Response());
    }

    public function test_non_admin_gets_403(): void
    {
        $_SESSION['user_permissions'] = ['dashboard' => 1, 'employees' => 1];

        $db = $this->createMock(Database::class);
        $db->expects($this->never())
            ->method('query'); // до базы дойти не должно

        $response = $this->callIndex($db);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Ключевой сценарий эскалации: POST с попыткой выдать себе admin.
     * Он тоже обязан упереться в 403 и не выполнить ни одного запроса.
     */
    public function test_non_admin_cannot_post_permission_change(): void
    {
        $_SESSION['user_permissions'] = ['dashboard' => 1];

        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/access')
            ->withParsedBody([
                'save_user_permissions' => '1',
                'perm_email'            => 'attacker@example.com',
                'perm_admin'            => '1',
            ]);

        $response = (new AccessController($db))->index($request, new Response());

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Permissions::can() строгий: пустая/битая сессия — это НЕ «пропустить».
     * Раньше легаси-проверки трактовали «прав не знаем» как «разрешить».
     */
    public function test_missing_permissions_in_session_is_denied(): void
    {
        // $_SESSION['user_permissions'] не задан вовсе
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');

        $this->assertSame(403, $this->callIndex($db)->getStatusCode());
    }

    public function test_admin_can_grant_and_revoke_manager_order_permission(): void
    {
        $_SESSION['user_permissions'] = ['admin' => true];
        $_SESSION['admin_access_csrf'] = 'tok';
        foreach ([true, false] as $grant) {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('fetchAll')->willReturn([]);
            $saved = null;
            $db = $this->createMock(Database::class);
            $db->method('t')->willReturn('users');
            $db->method('query')->willReturnCallback(function ($sql, $params = []) use ($statement, &$saved) {
                if (str_starts_with($sql, 'UPDATE')) $saved = $params;
                return $statement;
            });
            $body = ['save_user_permissions' => '1', 'perm_email' => 'manager@example.com', 'csrf' => 'tok'];
            if ($grant) $body['perm_neworder'] = '1';
            $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/access')->withParsedBody($body);
            $response = (new AccessController($db))->index($request, new Response());
            self::assertSame(200, $response->getStatusCode());
            self::assertSame((int) $grant, json_decode($saved[0], true)['neworder']);
            self::assertSame('manager@example.com', $saved[3]);
            self::assertNull($saved[2], 'Telegram ID не задан');
            self::assertStringContainsString('name="perm_neworder"', (string) $response->getBody());
        }
    }

    public function test_aibot_office_is_separate_explicit_checkbox(): void
    {
        $_SESSION['user_permissions'] = ['admin' => true];
        $_SESSION['admin_access_csrf'] = 'tok';
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);
        $saved = null;
        $db = $this->createMock(Database::class);
        $db->method('t')->willReturn('users');
        $db->method('query')->willReturnCallback(function ($sql, $params = []) use ($statement, &$saved) {
            if (str_starts_with($sql, 'UPDATE')) $saved = [$sql, $params];
            return $statement;
        });
        $body = ['save_user_permissions' => '1', 'perm_email' => 'owner@example.com', 'csrf' => 'tok',
            'perm_aibot_office' => '1', 'perm_tg_user_id' => '169510539'];
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/access')->withParsedBody($body);
        $response = (new AccessController($db))->index($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        $perms = json_decode($saved[1][0], true);
        self::assertSame(1, $perms['aibot_office']);
        self::assertSame(0, $perms['aibot_finance'], 'office не включает finance');
        $html = (string) $response->getBody();
        self::assertStringContainsString('name="perm_aibot_office"', $html);
        self::assertStringContainsString('Бот: задачи в офис (Telegram)', $html);
        $c = new \ReflectionClassConstant(\App\Services\UserPermissionsService::class, 'DEFAULTS');
        self::assertFalse($c->getValue()['aibot_office']);
    }

    public function test_aibot_finance_is_explicit_checkbox_and_tg_id_is_saved(): void
    {
        $_SESSION['user_permissions'] = ['admin' => true];
        $_SESSION['admin_access_csrf'] = 'tok';
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);
        $saved = null;
        $db = $this->createMock(Database::class);
        $db->method('t')->willReturn('users');
        $db->method('query')->willReturnCallback(function ($sql, $params = []) use ($statement, &$saved) {
            if (str_starts_with($sql, 'UPDATE')) $saved = [$sql, $params];
            return $statement;
        });
        $body = ['save_user_permissions' => '1', 'perm_email' => 'owner@example.com', 'csrf' => 'tok',
            'perm_aibot_finance' => '1', 'perm_tg_user_id' => '169510539'];
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/access')->withParsedBody($body);
        $response = (new AccessController($db))->index($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('telegram_user_id = ?', $saved[0]);
        self::assertSame(1, json_decode($saved[1][0], true)['aibot_finance']);
        self::assertSame(169510539, $saved[1][2]);
        $html = (string) $response->getBody();
        self::assertStringContainsString('name="perm_aibot_finance"', $html);
        self::assertStringContainsString('Бот: финансы (Telegram)', $html);
        self::assertStringContainsString('name="perm_tg_user_id"', $html);
    }

    public function test_tg_id_must_be_numeric(): void
    {
        $_SESSION['user_permissions'] = ['admin' => true];
        $_SESSION['admin_access_csrf'] = 'tok';
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);
        $updated = false;
        $db = $this->createMock(Database::class);
        $db->method('t')->willReturn('users');
        $db->method('query')->willReturnCallback(function ($sql) use ($statement, &$updated) {
            if (str_starts_with($sql, 'UPDATE')) $updated = true;
            return $statement;
        });
        $body = ['save_user_permissions' => '1', 'perm_email' => 'x@example.com', 'perm_tg_user_id' => '@zapleosoft', 'csrf' => 'tok'];
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/access')->withParsedBody($body);
        (new AccessController($db))->index($request, new Response());
        self::assertFalse($updated, 'username вместо числа не сохраняется');
    }

    // ─── CSRF на изменяющие запросы /admin/access ───────────────────────────

    /** @return array{0:Database,1:\ArrayObject} мок БД + журнал изменяющих SQL */
    private function recordingDb(): array
    {
        $log = new \ArrayObject();
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([]);
        $db = $this->createMock(Database::class);
        $db->method('t')->willReturn('users');
        $db->method('query')->willReturnCallback(function ($sql) use ($statement, $log) {
            if (preg_match('/^\s*(UPDATE|INSERT|DELETE|ALTER)/i', $sql)) $log[] = $sql;
            return $statement;
        });
        return [$db, $log];
    }

    /** @return iterable<string,array{array,array}> [body, query] изменяющих запросов */
    public static function mutatingRequests(): iterable
    {
        $grant = ['save_user_permissions' => '1', 'perm_email' => 'owner@example.com', 'perm_aibot_finance' => '1', 'perm_tg_user_id' => '169510539'];
        yield 'выдача права и TG id' => [$grant, []];
        yield 'добавление пользователя' => [['add_email' => '1', 'email' => 'new@example.com'], []];
        yield 'удаление (GET)' => [[], ['delete' => 'victim@example.com']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutatingRequests')]
    public function test_mutation_without_or_with_wrong_csrf_is_rejected(array $body, array $query): void
    {
        $_SESSION['user_permissions'] = ['admin' => true];
        $_SESSION['admin_access_csrf'] = str_repeat('a', 64);
        foreach (['' => 'без токена', str_repeat('b', 64) => 'чужой токен'] as $token => $case) {
            [$db, $log] = $this->recordingDb();
            $b = $body; $q = $query;
            if ($token !== '') { $body !== [] ? $b['csrf'] = $token : $q['csrf'] = $token; }
            $method = $body !== [] ? 'POST' : 'GET';
            $request = (new ServerRequestFactory())->createServerRequest($method, '/admin/access')->withQueryParams($q)->withParsedBody($b ?: null);
            $response = (new AccessController($db))->index($request, new Response());
            self::assertSame(403, $response->getStatusCode(), $case);
            self::assertSame([], $log->getArrayCopy(), "{$case}: права/TG id/пользователи не меняются");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mutatingRequests')]
    public function test_mutation_with_valid_csrf_passes(array $body, array $query): void
    {
        $_SESSION['user_permissions'] = ['admin' => true];
        $_SESSION['admin_access_csrf'] = str_repeat('a', 64);
        [$db, $log] = $this->recordingDb();
        $body !== [] ? $body['csrf'] = str_repeat('a', 64) : $query['csrf'] = str_repeat('a', 64);
        $method = $body !== [] ? 'POST' : 'GET';
        $request = (new ServerRequestFactory())->createServerRequest($method, '/admin/access')->withQueryParams($query)->withParsedBody($body ?: null);
        $response = (new AccessController($db))->index($request, new Response());
        self::assertSame(200, $response->getStatusCode());
        self::assertNotSame([], array_filter($log->getArrayCopy(), fn($s) => !str_starts_with(ltrim($s), 'ALTER')));
    }

    public function test_page_renders_token_in_forms_and_delete_links(): void
    {
        $_SESSION['user_permissions'] = ['admin' => true];
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('fetchAll')->willReturn([['email' => 'u@example.com', 'name' => '', 'telegram_username' => '', 'telegram_user_id' => null, 'permissions_json' => '{}', 'created_at' => '2026-01-01']]);
        $db = $this->createMock(Database::class);
        $db->method('t')->willReturn('users');
        $db->method('query')->willReturn($statement);
        $response = (new AccessController($db))->index((new ServerRequestFactory())->createServerRequest('GET', '/admin/access'), new Response());
        $token = $_SESSION['admin_access_csrf'] ?? '';
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $html = (string) $response->getBody();
        self::assertSame(2, substr_count($html, 'name="csrf" value="' . $token . '"'), 'обе формы');
        self::assertStringContainsString('&amp;csrf=' . $token, $html, 'ссылка удаления');
    }
}
