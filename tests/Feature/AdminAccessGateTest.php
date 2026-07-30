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
}
