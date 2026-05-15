<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Infrastructure\Config;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class WebhookSecretMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expected = Config::get('TELEGRAM_WEBHOOK_SECRET');

        // If no secret is configured — allow all (dev/testing)
        if ($expected === '') {
            return $handler->handle($request);
        }

        // Prefer X-Telegram-Bot-Api-Secret-Token header (official Telegram approach)
        $provided = $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token');

        // Fallback: legacy ?secret= query param
        if ($provided === '') {
            $params   = $request->getQueryParams();
            $provided = trim((string) ($params['secret'] ?? ''));
        }

        if (!hash_equals($expected, $provided)) {
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write('Forbidden');
            return $response;
        }

        return $handler->handle($request);
    }
}
