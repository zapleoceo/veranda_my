<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Infrastructure\Permissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Гейт «нужно право X» на уровне роута или группы роутов.
 *
 * Зачем: право проверялось только при рендере HTML-страницы, а REST-эндпоинты
 * той же подсистемы оставались открытыми любому залогиненному. Для payday3 это
 * означало, что сотрудник без права `payday` не мог открыть страницу, но мог
 * дёрнуть POST /payday3/api/poster/finance/transactions и создать реальную
 * проводку в Poster. Один гейт на группу закрывает все 38 эндпоинтов разом и
 * не даёт забыть проверку в новом action'е.
 *
 * Ставится ПОСЛЕ AuthMiddleware в цепочке добавления:
 *     $app->group(...)->add(RequirePermission::for('payday'))->add(AuthMiddleware::class);
 * Slim выполняет middleware в обратном порядке добавления, поэтому первым
 * отработает AuthMiddleware (поднимет сессию и права), а затем этот гейт.
 */
final class RequirePermission implements MiddlewareInterface
{
    public function __construct(private readonly string $permissionKey) {}

    public static function for(string $permissionKey): self
    {
        return new self($permissionKey);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (Permissions::can($this->permissionKey)) {
            return $handler->handle($request);
        }

        // AJAX-клиенты payday3 ждут JSON-конверт; отдать им HTML-страницу
        // «Forbidden» значит показать пользователю поломанный интерфейс
        // вместо внятной ошибки.
        $response = new Response(403);
        if (self::wantsJson($request)) {
            $body = json_encode(
                ['ok' => false, 'error' => 'Forbidden'],
                JSON_UNESCAPED_UNICODE
            );
            $response = $response
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Cache-Control', 'no-store');
            $response->getBody()->write($body !== false ? $body : '{"ok":false,"error":"Forbidden"}');
            return $response;
        }

        $response = $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->getBody()->write('Forbidden');
        return $response;
    }

    /** Тот же критерий, что и в AuthMiddleware — поведение не должно расходиться. */
    private static function wantsJson(ServerRequestInterface $r): bool
    {
        $accept = strtolower($r->getHeaderLine('Accept'));
        if ($accept !== '' && str_contains($accept, 'application/json')) return true;
        if (strcasecmp($r->getHeaderLine('X-Requested-With'), 'XMLHttpRequest') === 0) return true;
        $ct = strtolower($r->getHeaderLine('Content-Type'));
        if ($ct !== '' && str_contains($ct, 'application/json')) return true;
        return str_contains($r->getUri()->getPath(), '/api/');
    }
}
