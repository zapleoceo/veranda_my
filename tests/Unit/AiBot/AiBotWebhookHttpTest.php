<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot;

use App\AiBot\AiBotWebhookController;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * HTTP-уровень: настоящие container.php + routes.php (маршрут /aibot_webhook и
 * middleware с секретом AIBOT_WEBHOOK_SECRET), контроллер — с фейками.
 */
final class AiBotWebhookHttpTest extends TestCase
{
    private const SECRET = 'test-secret-only';

    private AiBotFlowTest $h;
    private App $app;
    private ?string $prevSecret = null;

    protected function setUp(): void
    {
        $this->prevSecret = $_ENV['AIBOT_WEBHOOK_SECRET'] ?? null;
        $_ENV['AIBOT_WEBHOOK_SECRET'] = self::SECRET;

        $this->h = new AiBotFlowTest('harness');
        $this->h->boot();
        $this->app = self::buildApp($this->h->ctl);
    }

    protected function tearDown(): void
    {
        if ($this->prevSecret === null) {
            unset($_ENV['AIBOT_WEBHOOK_SECRET']);
        } else {
            $_ENV['AIBOT_WEBHOOK_SECRET'] = $this->prevSecret;
        }
    }

    public static function buildApp(AiBotWebhookController $ctl): App
    {
        $b = new ContainerBuilder();
        $b->addDefinitions(require __DIR__ . '/../../../src/Bootstrap/container.php');
        $b->addDefinitions([AiBotWebhookController::class => $ctl]);
        AppFactory::setContainer($b->build());
        $app = AppFactory::create();
        $app->addRoutingMiddleware();
        require __DIR__ . '/../../../src/Bootstrap/routes.php';
        return $app;
    }

    public static function send(App $app, array $update, ?string $secret): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/aibot_webhook')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream((string) json_encode($update, JSON_UNESCAPED_UNICODE)));
        if ($secret !== null) {
            $req = $req->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret);
        }
        return $app->handle($req);
    }

    public function test_secret_is_required(): void
    {
        $this->assertSame(403, self::send($this->app, AiBotFlowTest::command(), null)->getStatusCode());
        $this->assertSame(403, self::send($this->app, AiBotFlowTest::command(), 'wrong')->getStatusCode());
        $this->assertSame([], $this->h->repo->rows);
    }

    public function test_main_bot_secret_does_not_open_aibot(): void
    {
        $prev = $_ENV['TELEGRAM_WEBHOOK_SECRET'] ?? null;
        $_ENV['TELEGRAM_WEBHOOK_SECRET'] = 'main-secret-test';
        try {
            $this->assertSame(403, self::send($this->app, AiBotFlowTest::command(), 'main-secret-test')->getStatusCode());
        } finally {
            if ($prev === null) { unset($_ENV['TELEGRAM_WEBHOOK_SECRET']); } else { $_ENV['TELEGRAM_WEBHOOK_SECRET'] = $prev; }
        }
    }

    public function test_wa_event_bypass_is_off_for_aibot(): void
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/aibot_webhook?wa_event=x');
        $this->assertSame(403, $this->app->handle($req)->getStatusCode());
    }

    public function test_valid_request_creates_draft(): void
    {
        $res = self::send($this->app, AiBotFlowTest::command(), self::SECRET);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('ok', (string) $res->getBody());
        $this->assertCount(1, $this->h->repo->rows);
        $this->assertCount(1, $this->h->tg->callsTo('sendMessage'));
        $this->assertSame([], $this->h->poster->callsTo('finance.createTransactions'));
    }

    public function test_empty_secret_config_is_503(): void
    {
        $_ENV['AIBOT_WEBHOOK_SECRET'] = '';
        $this->assertSame(503, self::send($this->app, AiBotFlowTest::command(), '')->getStatusCode());
    }
}
