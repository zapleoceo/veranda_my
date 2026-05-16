<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\UserPermissionsService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly UserPermissionsService   $permissions,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['user_email'])) {
            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', '/login');
        }

        $this->permissions->loadIntoSession((string) $_SESSION['user_email']);

        return $handler->handle($request->withAttribute('user_email', $_SESSION['user_email']));
    }
}
