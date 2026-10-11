<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot;

use App\AiBot\AiBotConfig;
use App\AiBot\HttpOfficeOutboxSender;
use App\AiBot\NullOfficeOutboxSender;
use App\AiBot\NullOfficePoller;
use App\AiBot\OfficeApiClient;
use App\AiBot\OfficeApiConfig;
use App\AiBot\OfficePoller;
use App\AiBot\OfficeTaskService;
use App\AiBot\OfficeTransportException;
use App\AiBot\OfficeWiring;
use App\AiBot\TelegramPermissionAuthorizer;
use App\AiBot\UserFinanceAuthorizer;
use App\Infrastructure\TelegramBotClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Unit\AiBot\Fakes\GrantTable;
use Tests\Unit\AiBot\Fakes\InMemoryOfficeRepository;
use Tests\Unit\AiBot\Fakes\InterleavingLock;
use Tests\Unit\AiBot\Fakes\RecordingHttp;
use Tests\Unit\AiBot\Fakes\ScriptedOfficeTransport;

/** Отправитель в офис и поллер: HTTP и Telegram замоканы, БД в памяти. */
final class OfficeSenderTest extends TestCase
{
    private const TOKEN = 'sekret-office-token-0123456789abcdef';
    private const CHAT = '-1001';
    private const NOW = 1791601200;

    private InMemoryOfficeRepository $repo;
    private ScriptedOfficeTransport $http;
    private RecordingHttp $tg;
    private AbstractLogger $log;
    /** @var list<string> */
    private array $logLines = [];
    /** @var list<string> */
    private array $notes = [];
    private HttpOfficeOutboxSender $sender;
    private OfficeApiClient $api;
    private OfficeTaskService $service;
    private TelegramBotClient $bot;

    protected function setUp(): void
    {
        $this->repo = new InMemoryOfficeRepository();
        $this->http = new ScriptedOfficeTransport();
        $this->tg = new RecordingHttp();
        $lines = &$this->logLines;
        $this->log = new class ($lines) extends AbstractLogger {
            public function __construct(private array &$lines) {}
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
            }
        };
        $cfg = OfficeApiConfig::fromConfig(static fn(string $k): string => ['OFFICE_API_URL' => 'https://vera.test/', 'OFFICE_SERVICE_TOKEN' => self::TOKEN][$k] ?? '');
        $this->api = new OfficeApiClient($cfg, $this->http);
        $notes = &$this->notes;
        $this->sender = new HttpOfficeOutboxSender($this->repo, $this->api, $this->log,
            static function (array $t, string $text) use (&$notes): void { $notes[] = $text; }, static fn(): int => self::NOW);

        $grants = new GrantTable();
        $grants->add(777, 'office@x', ['aibot_office' => true]);
        $this->bot = new TelegramBotClient('tg-token', $this->tg);
        $this->service = new OfficeTaskService(
            new AiBotConfig([], [self::CHAT], authorizer: new UserFinanceAuthorizer($grants)),
            $this->repo, $this->bot, new TelegramPermissionAuthorizer($grants, 'aibot_office'),
            new InterleavingLock(), $this->log, static fn(): int => self::NOW);
    }

    /** @param array<string,mixed> $over */
    private function seedTask(array $over = []): array
    {
        $row = $over + ['task_key' => 'vb--1001-600', 'chat_id' => self::CHAT, 'thread_id' => null, 'origin_message_id' => 600,
            'origin_user_id' => 777, 'update_id' => 91, 'text' => 'заказать лёд', 'status' => 'queued_local', 'revision' => 1];
        $this->repo->insertTask($row);
        return $this->repo->findTaskByKey($row['task_key']);
    }

    /** @param array<string,mixed> $payload */
    private function seedCreate(array $payload = []): array
    {
        $p = $payload + ['task_key' => 'vb--1001-600', 'chat_id' => self::CHAT, 'thread_id' => null, 'origin_message_id' => 600,
            'origin_user_id' => 777, 'update_id' => 91, 'text' => ' заказать лёд ', 'context' => null, 'media' => [], 'revision' => 1];
        $this->repo->insertOutbox(['idempotency_key' => 'vb--1001-600', 'task_key' => 'vb--1001-600', 'kind' => 'create',
            'payload_json' => json_encode($p, JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'attempts' => 0]);
        return $this->repo->findOutboxByKey('vb--1001-600');
    }

    private function seedAnswer(string $qid = '12'): array
    {
        $this->repo->insertOutbox(['idempotency_key' => 'vb-ans--1001-650', 'task_key' => 'vb--1001-600', 'kind' => 'answer',
            'payload_json' => json_encode(['task_key' => 'vb--1001-600', 'question_id' => $qid, 'text' => 'да, 5 кг', 'revision' => 2]),
            'status' => 'pending', 'attempts' => 0]);
        return $this->repo->findOutboxByKey('vb-ans--1001-650');
    }

    private function assertNoNulls(mixed $v, string $path = ''): void
    {
        if (is_array($v)) {
            foreach ($v as $k => $x) {
                $this->assertNotNull($x, "null at {$path}/{$k}");
                $this->assertNoNulls($x, "{$path}/{$k}");
            }
        }
    }

    public function testCreateCanonicalPayloadAndSuccess(): void
    {
        $this->seedTask();
        $row = $this->seedCreate(['thread_id' => 5, 'context' => ['reply_to' => ['message_id' => 590, 'user_id' => null,
            'text' => 'цитата', 'media' => [['type' => 'voice', 'file_id' => 'F']]]],
            'media' => [['type' => 'photo', 'file_id' => 'PF', 'file_unique_id' => '', 'mime_type' => 'image/jpeg', 'file_size' => 10, 'width' => 4, 'height' => 3]]]);
        $this->http->push(201, ['task_id' => 'vb-abc', 'revision' => 40, 'status' => 'open']);

        $this->assertTrue($this->sender->send($row));

        $body = $this->http->bodies()[0];
        $this->assertNoNulls($body);
        $this->assertSame('tg--1001-600', $body['client_task_id']);
        $this->assertSame('заказать лёд', $body['title']);
        $this->assertSame(['channel' => 'telegram', 'chat' => '-1001', 'message' => 600, 'from_id' => 777, 'thread' => 5], $body['origin']);
        $this->assertSame(['channel' => 'telegram', 'chat' => '-1001', 'reply_to' => 600, 'thread' => 5], $body['result_recipient']);
        $this->assertSame(['reply_to' => ['id' => 590, 'text' => 'цитата', 'media' => 'voice']], $body['context']);
        $this->assertSame([['type' => 'photo', 'file_id' => 'PF', 'mime' => 'image/jpeg', 'size' => 10, 'w' => 4, 'h' => 3]], $body['attachments']);
        $this->assertSame('91', $body['causation_id']);
        $this->assertSame('Bearer ' . self::TOKEN, $this->http->calls[0]['headers']['Authorization']);
        $this->assertSame('https://vera.test/office/v1/tasks', $this->http->calls[0]['url']);

        $t = $this->repo->findTaskByKey('vb--1001-600');
        $this->assertSame('vb-abc', $t['office_task_id']);
        $this->assertSame(40, $t['office_revision']);
        $this->assertSame('waiting_executor', $t['status']);
        $this->assertSame('sent', $this->repo->findOutboxByKey('vb--1001-600')['status']);
    }

    public function testTitleAndBodyLimits(): void
    {
        $long = str_repeat('я', 9000);
        $req = HttpOfficeOutboxSender::buildCreate(['chat_id' => '1', 'origin_message_id' => 2, 'origin_user_id' => 3, 'text' => $long, 'update_id' => 4]);
        $this->assertSame(200, mb_strlen($req['title']));
        $this->assertSame(8000, mb_strlen($req['body']));
        $this->assertArrayNotHasKey('context', $req);
        $this->assertArrayNotHasKey('attachments', $req);
    }

    public function testPayloadIdenticalAcrossRetries(): void
    {
        $this->seedTask();
        $row = $this->seedCreate();
        $this->http->pushError(new OfficeTransportException('timeout'))->push(200, ['task_id' => 'vb-abc', 'revision' => 1]);

        $this->assertFalse($this->sender->send($row));
        $pending = $this->repo->findOutboxByKey('vb--1001-600');
        $this->assertSame('pending', $pending['status']);
        $this->assertSame(1, $pending['attempts']);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW + 60), $pending['next_attempt_at']);

        $this->assertTrue($this->sender->send($pending));
        $this->assertSame($this->http->calls[0]['body'], $this->http->calls[1]['body']);
    }

    public function testRetryAfterOn503And429(): void
    {
        $this->seedTask();
        $row = $this->seedCreate();
        $this->http->push(503, ['error' => 'in_progress', 'retryable' => true], ['retry-after' => '7']);
        $this->assertFalse($this->sender->send($row));
        $r = $this->repo->findOutboxByKey('vb--1001-600');
        $this->assertSame('pending', $r['status']);
        $this->assertSame(date('Y-m-d H:i:s', self::NOW + 7), $r['next_attempt_at']);
        $this->assertSame([], $this->repo->dueOutbox(10, date('Y-m-d H:i:s', self::NOW)));
        $this->assertCount(1, $this->repo->dueOutbox(10, date('Y-m-d H:i:s', self::NOW + 7)));
    }

    public function testPayloadMismatchFailsAndNotifies(): void
    {
        $this->seedTask();
        $row = $this->seedCreate();
        $this->http->push(409, ['error' => 'payload_mismatch', 'retryable' => false, 'detail' => 'секретный текст']);
        $this->assertFalse($this->sender->send($row));
        $r = $this->repo->findOutboxByKey('vb--1001-600');
        $this->assertSame('failed', $r['status']);
        $this->assertSame('http_409:payload_mismatch', $r['last_error']);
        $this->assertSame('failed', $this->repo->findTaskByKey('vb--1001-600')['status']);
        $this->assertCount(1, $this->notes);
        $this->assertStringContainsString('error aibot.office.send.failed', implode("\n", $this->logLines));
        $this->assertStringNotContainsString('секретный текст', implode("\n", $this->logLines) . json_encode($r, JSON_UNESCAPED_UNICODE));
    }

    public function testValidationErrorFails(): void
    {
        $this->seedTask();
        $row = $this->seedCreate();
        $this->http->push(422, ['error' => 'invalid_request', 'retryable' => false]);
        $this->assertFalse($this->sender->send($row));
        $this->assertSame('failed', $this->repo->findOutboxByKey('vb--1001-600')['status']);
    }

    public function testAuthFailureHaltsBatchAndKeepsRow(): void
    {
        $this->seedTask();
        $row = $this->seedCreate();
        $this->http->push(401, ['error' => 'unauthorized', 'retryable' => false]);
        $this->assertFalse($this->sender->send($row));
        $this->assertTrue($this->sender->isHalted());
        $r = $this->repo->findOutboxByKey('vb--1001-600');
        $this->assertSame('pending', $r['status']);
        $this->assertSame(0, $r['attempts']);
        $this->assertFalse($this->sender->send($row)); // без запроса: очередь транспорта пуста
        $this->assertCount(1, $this->http->calls);
    }

    public function testAnswerRevisionMismatchRereadsAndRetriesWithSameKey(): void
    {
        $this->seedTask(['office_task_id' => 'vb-abc', 'office_revision' => 40, 'status' => 'waiting_owner']);
        $row = $this->seedAnswer('12');
        $this->http->push(409, ['error' => 'revision_mismatch', 'retryable' => false])
            ->push(200, ['tasks' => [['task_id' => 'vb-other', 'revision' => 1], ['task_id' => 'vb-abc', 'revision' => 55]], 'next_cursor' => null])
            ->push(200, ['task_id' => 'vb-abc', 'question_id' => 12, 'status' => 'answered', 'revision' => 56, 'replayed' => false]);

        $this->assertTrue($this->sender->send($row));

        $posts = $this->http->bodies();
        $this->assertSame(['question_id' => 12, 'text' => 'да, 5 кг', 'expected_revision' => 40, 'idempotency_key' => 'vb-ans--1001-650'], $posts[0]);
        $this->assertSame(['question_id' => 12, 'text' => 'да, 5 кг', 'expected_revision' => 55, 'idempotency_key' => 'vb-ans--1001-650'], $posts[1]);
        $this->assertStringEndsWith('/office/v1/tasks/vb-abc/answers', $this->http->calls[0]['url']);
        $t = $this->repo->findTaskByKey('vb--1001-600');
        $this->assertSame(56, $t['office_revision']);
        $this->assertSame('waiting_executor', $t['status']);
        $this->assertSame('sent', $this->repo->findOutboxByKey('vb-ans--1001-650')['status']);
    }

    public function testAnswerWaitsForCreateWithoutNetwork(): void
    {
        $this->seedTask();
        $row = $this->seedAnswer();
        $this->assertFalse($this->sender->send($row));
        $this->assertSame([], $this->http->calls);
        $this->assertSame(0, $this->repo->findOutboxByKey('vb-ans--1001-650')['attempts']);
    }

    public function testAnswerQuestionClosedFailsOnlyOutbox(): void
    {
        $this->seedTask(['office_task_id' => 'vb-abc', 'office_revision' => 40, 'status' => 'waiting_owner']);
        $row = $this->seedAnswer();
        $this->http->push(404, ['error' => 'not_found', 'retryable' => false]);
        $this->assertFalse($this->sender->send($row));
        $this->assertSame('failed', $this->repo->findOutboxByKey('vb-ans--1001-650')['status']);
        $this->assertSame('waiting_owner', $this->repo->findTaskByKey('vb--1001-600')['status']);
        $this->assertStringContainsString('вопрос уже закрыт', $this->notes[0]);
    }

    public function testTokenNeverLeaks(): void
    {
        $this->seedTask();
        $row = $this->seedCreate();
        $this->http->pushError(new OfficeTransportException('connect failed for Bearer ' . self::TOKEN));
        $this->assertFalse($this->sender->send($row));
        $this->http->push(401, ['error' => 'unauthorized']);
        $this->sender->send($this->repo->findOutboxByKey('vb--1001-600'));

        $dump = implode("\n", $this->logLines) . json_encode($this->repo->outbox) . json_encode($this->repo->tasks) . implode("\n", $this->notes);
        $this->assertStringNotContainsString(self::TOKEN, $dump);

        $this->http->pushError(new OfficeTransportException('x ' . self::TOKEN));
        try {
            $this->api->listTasks(null, null);
            $this->fail('expected exception');
        } catch (OfficeTransportException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
        $this->assertStringNotContainsString(self::TOKEN, print_r(OfficeApiConfig::fromConfig(static fn() => self::TOKEN === '' ? '' : 'https://x'), true));
    }

    private function poller(): OfficePoller
    {
        return new OfficePoller($this->repo, $this->sender, $this->api, $this->service, $this->bot, $this->log, static fn(): int => self::NOW);
    }

    /** @param array<string,mixed> $over */
    private function item(array $over = []): array
    {
        return $over + ['task_id' => 'vb-abc', 'client_task_id' => 'tg--1001-600', 'status' => 'open', 'questions' => [], 'result' => null,
            'revision' => 50, 'delivered_revision' => 0, 'lease_live' => false, 'updated_at' => '2026-10-11T10:00:00.500000'];
    }

    public function testPollerDeliversQuestionOnceAndResultOnce(): void
    {
        $this->seedTask(['office_task_id' => 'vb-abc', 'office_revision' => 40, 'status' => 'waiting_executor']);
        // проход 1: вопрос
        $this->http->push(200, ['tasks' => [$this->item(['questions' => [['question_id' => 12, 'text' => 'Сколько <кг>?']]])], 'next_cursor' => null])
            ->push(200, ['task_id' => 'vb-abc', 'delivered_revision' => 50]);
        $st = $this->poller()->run();
        $this->assertSame(1, $st['questions']);
        $this->assertSame(1, $this->tg->calls === [] ? 0 : count($this->tg->callsTo('sendMessage')));
        $this->assertStringContainsString('Сколько &lt;кг&gt;?', $this->tg->callsTo('sendMessage')[0]['params']['text']);
        $t = $this->repo->findTaskByKey('vb--1001-600');
        $this->assertSame('waiting_owner', $t['status']);
        $this->assertSame('12', $t['last_question_id']);
        $this->assertSame(50, $t['office_delivered_revision']);
        $this->assertSame(['revision' => 50], $this->http->bodies()[0]);
        $this->assertStringEndsWith('/tasks/vb-abc/delivered', $this->http->calls[1]['url']);
        $this->assertSame('2026-10-11T09:59:59', $this->repo->getState('poll_since'));

        // проход 2: то же состояние (delivered_revision уже 50) -> без сообщений и /delivered
        $this->http->push(200, ['tasks' => [$this->item(['delivered_revision' => 50])], 'next_cursor' => null]);
        $this->poller()->run();
        $this->assertCount(1, $this->tg->callsTo('sendMessage'));
        $this->assertStringContainsString('updated_since=2026-10-11T09%3A59%3A59', $this->http->calls[2]['url']);
        $this->assertCount(3, $this->http->calls);

        // проход 3: результат
        $done = $this->item(['status' => 'done', 'result' => 'Лёд заказан <ok>', 'revision' => 70, 'updated_at' => '2026-10-11T10:05:00']);
        $this->http->push(200, ['tasks' => [$done], 'next_cursor' => null])->push(200, ['delivered_revision' => 70]);
        $st = $this->poller()->run();
        $this->assertSame(1, $st['results']);
        $sent = $this->tg->callsTo('sendMessage');
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Лёд заказан &lt;ok&gt;', $sent[1]['params']['text']);
        $t = $this->repo->findTaskByKey('vb--1001-600');
        $this->assertSame('done', $t['status']);
        $this->assertNotNull($t['result_delivered_at']);
        $this->assertSame(70, $t['office_delivered_revision']);

        // проход 4: повтор done -> тишина, delivered не растёт назад
        $this->http->push(200, ['tasks' => [$done], 'next_cursor' => null]);
        $this->poller()->run();
        $this->assertCount(2, $this->tg->callsTo('sendMessage'));
        $this->assertSame(70, $this->repo->findTaskByKey('vb--1001-600')['office_delivered_revision']);
    }

    public function testPollerMapsLeaseAndHoldsCursorOnFailure(): void
    {
        $this->seedTask(['office_task_id' => 'vb-abc', 'office_revision' => 40, 'status' => 'waiting_executor']);
        $this->http->push(200, ['tasks' => [$this->item(['lease_live' => true])], 'next_cursor' => null])->push(200, []);
        $this->poller()->run();
        $t = $this->repo->findTaskByKey('vb--1001-600');
        $this->assertSame('in_progress', $t['status']);
        $this->assertSame(1, $t['lease_live']);

        // /delivered падает -> ревизия не отмечена, курсор держится на этой задаче
        $this->repo->setState('poll_since', '2026-10-11T00:00:00');
        $this->http->push(200, ['tasks' => [$this->item(['revision' => 60, 'updated_at' => '2026-10-11T10:30:00'])], 'next_cursor' => null])
            ->push(503, ['error' => 'in_progress', 'retryable' => true]);
        $st = $this->poller()->run();
        $this->assertSame(1, $st['errors']);
        $this->assertSame(50, $this->repo->findTaskByKey('vb--1001-600')['office_delivered_revision']);
        $this->assertSame('2026-10-11T10:29:59', $this->repo->getState('poll_since'));
    }

    public function testPollerProcessesOutboxBeforePolling(): void
    {
        $this->seedTask();
        $this->seedCreate();
        $this->http->push(201, ['task_id' => 'vb-abc', 'revision' => 5])->push(200, ['tasks' => [], 'next_cursor' => null]);
        $st = $this->poller()->run();
        $this->assertSame(1, $st['sent']);
        $this->assertSame(1, $this->repo->ensureCalls);
        $this->assertSame('POST', $this->http->calls[0]['method']);
        $this->assertSame('GET', $this->http->calls[1]['method']);
    }

    public function testNullWiringTouchesNothing(): void
    {
        $get = static fn(string $k): string => ['OFFICE_API_URL' => 'https://vera.test'][$k] ?? ''; // токена нет
        $this->assertNull(OfficeWiring::config($get));
        $client = OfficeWiring::client(OfficeWiring::config($get));
        $this->assertNull($client);
        $this->assertInstanceOf(NullOfficeOutboxSender::class, OfficeWiring::sender($client, $this->repo, $this->log, $this->bot));
        $poller = OfficeWiring::poller($client, $this->repo, new NullOfficeOutboxSender(), $this->service, $this->bot, $this->log);
        $this->assertInstanceOf(NullOfficePoller::class, $poller);
        $calls = $this->repo->calls;
        $poller->run();
        $this->assertSame(0, $this->repo->ensureCalls);
        $this->assertSame($calls, $this->repo->calls);
        $this->assertSame([], $this->http->calls);
        $this->assertSame([], $this->tg->calls);
        $this->assertFalse((new NullOfficeOutboxSender())->send(['kind' => 'create']));
    }

    public function testWiringSelectsRealSenderOnlyWhenBothKeysSet(): void
    {
        $both = static fn(string $k): string => ['OFFICE_API_URL' => 'https://vera.test', 'OFFICE_SERVICE_TOKEN' => self::TOKEN][$k] ?? '';
        $onlyToken = static fn(string $k): string => ['OFFICE_SERVICE_TOKEN' => self::TOKEN][$k] ?? '';
        $this->assertNull(OfficeWiring::config($onlyToken));
        $client = OfficeWiring::client(OfficeWiring::config($both), $this->http);
        $this->assertInstanceOf(HttpOfficeOutboxSender::class, OfficeWiring::sender($client, $this->repo, $this->log, $this->bot));
        $this->assertInstanceOf(OfficePoller::class, OfficeWiring::poller($client, $this->repo, $this->sender, $this->service, $this->bot, $this->log));
    }
}
