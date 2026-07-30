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
        // События WA-моста проверяют себя сами: WaEventHandler сверяет
        // WA_NODE_SECRET/WA_BRIDGE_SECRET через hash_equals и отдаёт 403 при
        // пустом или неверном значении (см. WaEventHandler::_authorised).
        // Поэтому пропуск здесь — не дыра, а передача проверки владельцу.
        if (isset($request->getQueryParams()['wa_event'])) {
            return $handler->handle($request);
        }

        $expected = Config::get('TELEGRAM_WEBHOOK_SECRET');

        // Пустой секрет = отказ, а НЕ «пропустить всё».
        //
        // Раньше здесь стоял allow-all «для dev/testing». Цена такого
        // удобства: стоит секрету исчезнуть из .env (опечатка, неполный
        // деплой, откат конфига) — и вебхук молча становится открытым.
        // А авторизация действий внутри идёт по username из тела запроса,
        // то есть подделать callback от админа тривиально: кнопки
        // vposter/vdecline/vrestore и игноры выполнятся от его имени.
        // Лучше видимо сломать доставку вебхука, чем незаметно её открыть.
        if ($expected === '') {
            $response = $this->responseFactory->createResponse(503);
            $response->getBody()->write('Webhook secret is not configured');
            return $response;
        }

        // Только официальный заголовок Telegram. Legacy-фолбэк на ?secret=
        // убран: секрет в query-строке оседает в access-логах nginx и в
        // Referer, а текущая регистрация вебхука его не использует
        // (проверено через getWebhookInfo: url без query-параметров).
        $provided = $request->getHeaderLine('X-Telegram-Bot-Api-Secret-Token');

        if ($provided === '' || !hash_equals($expected, $provided)) {
            $response = $this->responseFactory->createResponse(403);
            $response->getBody()->write('Forbidden');
            return $response;
        }

        return $handler->handle($request);
    }
}
