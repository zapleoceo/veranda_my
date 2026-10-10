<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot;

use App\AiBot\AiBotConfig;
use App\AiBot\AiBotWebhookController;
use App\AiBot\FinanceDraftService;
use App\AiBot\PayoutExtractorInterface;
use App\AiBot\PayoutParser;
use App\Infrastructure\TelegramBotClient;
use App\Payday3\Contracts\PosterLookupServiceInterface;
use App\Payday3\Services\PosterTransactionCreateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Response;
use Tests\Unit\AiBot\Fakes\InMemoryDraftRepository;
use Tests\Unit\AiBot\Fakes\RecordingHttp;
use Tests\Unit\Payday3\Fakes\FixedSettings;
use Tests\Unit\Payday3\Fakes\InMemoryAuditLog;
use Tests\Unit\Payday3\Fakes\PassThroughLock;
use Tests\Unit\Payday3\Fakes\ScriptedPoster;

/**
 * Сквозной сценарий через контроллер: фейковый Telegram (RecordingHttp под
 * настоящим TelegramBotClient) и фейковый Poster (ScriptedPoster под
 * настоящим PosterTransactionCreateService). Сети нет.
 */
final class AiBotFlowTest extends TestCase
{
    public const OWNER = 111;
    public const OTHER = 222;
    public const CHAT = '-1001';
    /** 2026-10-10 10:00 Asia/Ho_Chi_Minh */
    public const NOW = 1791601200;

    public RecordingHttp $tg;
    public ScriptedPoster $poster;
    public InMemoryDraftRepository $repo;
    public InMemoryAuditLog $audit;
    public PassThroughLock $lock;
    public AiBotWebhookController $ctl;
    /** @var list<array{level:string,message:string}> */
    public array $logs = [];
    public ?PayoutExtractorInterface $extractor = null;
    private int $nextTx = 20000;

    /** @param list<array<string,mixed>> $existing ответ finance.getTransactions */
    public function boot(array $existing = [], ?\Closure $create = null, array $owners = [self::OWNER], array $chats = [self::CHAT], int $approver = self::OWNER): void
    {
        $this->tg = new RecordingHttp();
        $this->poster = new ScriptedPoster([
            'finance.getTransactions' => $existing,
            'finance.createTransactions' => $create ?? fn(array $p) => ++$this->nextTx,
        ]);
        $this->repo = new InMemoryDraftRepository();
        $this->audit = new InMemoryAuditLog();
        $this->lock = new PassThroughLock();
        $settings = FixedSettings::defaults();
        $lookup = new class implements PosterLookupServiceInterface {
            public function employees(): array { return []; }
            public function financeAccounts(): array { return [1 => '1 Счет Стаса', 2 => 'Касса', 8 => 'Tips', 9 => 'Vietnam Comp', 11 => 'Zana4ka']; }
            public function financeCategories(): array { return []; }
        };
        $clock = static fn(): int => self::NOW;
        $svc = new FinanceDraftService(
            $this->repo,
            new PosterTransactionCreateService($this->poster, $settings, $this->audit, $this->lock),
            $this->poster, $settings, $lookup, $this->audit, $this->lock, $clock,
        );
        $this->ctl = new AiBotWebhookController(
            new AiBotConfig($owners, $chats, duplicateApproverTgId: $approver),
            $svc,
            new TelegramBotClient('test-token', $this->tg),
            new PayoutParser(),
            $this->extractor,
            $this->logger(),
            $clock,
            static fn(\Closure $j) => $j(),
        );
    }

    private function logger(): AbstractLogger
    {
        $this->logs = [];
        $sink = &$this->logs;
        return new class ($sink) extends AbstractLogger {
            public function __construct(private array &$sink) {}
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->sink[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }

    public function post(array $update): string
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/aibot_webhook')
            ->withBody((new StreamFactory())->createStream((string) json_encode($update, JSON_UNESCAPED_UNICODE)));
        return (string) $this->ctl->handle($req, new Response())->getBody();
    }

    public static function command(array $over = [], string $source = PayoutParserTest::IGOR, int $sourceId = 500): array
    {
        return ['update_id' => 1, 'message' => array_merge([
            'message_id' => 600,
            'date' => self::NOW - 30,
            'chat' => ['id' => (int) self::CHAT, 'type' => 'supergroup'],
            'from' => ['id' => self::OWNER, 'username' => 'dmitry'],
            'text' => 'внеси, категория инвесторы, трата выплата дивидендов, в комментарий имя',
            'reply_to_message' => [
                'message_id' => $sourceId, 'date' => self::NOW - 86400,
                'from' => ['id' => 333, 'first_name' => 'Игорь'], 'text' => $source,
            ],
        ], $over)];
    }

    public function press(string $data, int $from = self::OWNER, int $cardId = 0): string
    {
        $cardId = $cardId ?: (int) ($this->repo->rows[1]['card_msg_id'] ?? 0);
        return $this->post(['update_id' => 2, 'callback_query' => [
            'id' => 'cb' . mt_rand(), 'data' => $data, 'from' => ['id' => $from, 'username' => 'dmitry'],
            'message' => ['message_id' => $cardId, 'chat' => ['id' => (int) self::CHAT]],
        ]]);
    }

    private function creates(): array
    {
        return $this->poster->callsTo('finance.createTransactions');
    }

    // ─── сценарии ──────────────────────────────────────────────────────────

    public function test_owner_command_creates_one_draft_card_without_writing(): void
    {
        $this->boot();
        $this->assertSame('ok', $this->post(self::command()));
        $this->assertCount(1, $this->repo->rows);
        $send = $this->tg->callsTo('sendMessage');
        $this->assertCount(1, $send);
        $text = $send[0]['params']['text'];
        $this->assertStringContainsString('Олег — 5 378 800 (переводы 5 000 000 + 378 800)', $text);
        $this->assertStringContainsString('Итого: 19 544 000', $text);
        $this->assertStringContainsString('Дивиденды сентябрь — Олег (часть 1/2)', $text);
        $this->assertStringContainsString('— выберите —', $text);
        $this->assertStringContainsString('"message_id":600', $send[0]['params']['reply_parameters']);
        $kb = json_decode($send[0]['params']['reply_markup'], true)['inline_keyboard'];
        $data = array_column(array_merge(...$kb), 'callback_data');
        $this->assertNotContains('fd_go:1', $data, '«Внести» недоступна без счёта и даты');
        $this->assertContains('fd_acc:1:1', $data);
        $this->assertContains('fd_date:1:20261010', $data);
        $this->assertContains('fd_date:1:20261009', $data, 'дата исходного сообщения');
        $this->assertSame([], $this->creates());
    }

    public function test_full_flow_split_choice_and_journal(): void
    {
        $this->boot();
        $this->post(self::command());
        $this->press('fd_acc:1:1');
        $this->press('fd_date:1:20261010');
        $this->assertSame('2026-10-10', $this->repo->rows[1]['tx_date']);
        $this->press('fd_split:1');
        $this->assertSame('person', $this->repo->rows[1]['split_mode']);
        $this->press('fd_split:1');
        $this->press('fd_go:1');

        $creates = $this->creates();
        $this->assertCount(6, $creates, 'Олег двумя переводами + 4 человека');
        $p = $creates[0]['params'];
        $this->assertSame(0, $p['type'], 'расход');
        $this->assertSame(22, $p['category']);
        $this->assertSame(1, $p['account_from']);
        $this->assertSame(5000000, $p['amount_from']);
        $this->assertSame('Дивиденды сентябрь — Олег (часть 1/2)', $p['comment']);
        $this->assertSame('2026-10-10 10:00:00', $p['date']);
        $this->assertSame('done', $this->repo->rows[1]['status']);
        $journal = array_values(array_filter($this->tg->callsTo('sendMessage'), fn($c) => str_contains($c['params']['text'], '📒')));
        $this->assertCount(1, $journal);
        $this->assertStringContainsString('#20001', $journal[0]['params']['text']);
        $this->assertSame(['tg:' . self::OWNER], array_values(array_unique(array_column($this->audit->rows, 'email'))));
        $this->assertContains('aibot.finance_draft.execute', array_column($this->audit->rows, 'action'));

        // Повторное нажатие и повторный триггер — ни одной новой записи.
        $this->press('fd_go:1');
        $this->post(self::command(['message_id' => 601]));
        $this->assertCount(6, $this->creates());
        $this->assertCount(1, $this->repo->rows, 'тот же источник → тот же черновик');
    }

    public function test_person_mode_sends_one_record_per_person(): void
    {
        $this->boot();
        $this->post(self::command());
        $this->press('fd_split:1');
        $this->press('fd_acc:1:2');
        $this->press('fd_date:1:20261009');
        $this->press('fd_go:1');
        $c = $this->creates();
        $this->assertCount(5, $c);
        $this->assertSame(5378800, $c[0]['params']['amount_from']);
        $this->assertSame(2, $c[0]['params']['account_from']);
        $this->assertSame('Дивиденды сентябрь — Олег', $c[0]['params']['comment']);
        $this->assertSame('2026-10-09 12:00:00', $c[0]['params']['date']);
    }

    public function test_already_in_poster_nothing_sent(): void
    {
        $existing = [];
        // Как в реальном Poster: комментарий — имя получателя.
        foreach ([11895 => [5000000, 'Олег'], 11896 => [378800, 'Олег'], 11897 => [3693200, 'Дима'], 11898 => [3978800, 'Ли'], 11899 => [4057200, 'Игорь'], 11900 => [2436000, 'Стас']] as $id => [$vnd, $who]) {
            $existing[] = ['transaction_id' => $id, 'account_id' => 1, 'category_id' => 22, 'type' => 0,
                'amount' => (string) (-$vnd * 100), 'date' => '2026-10-09 15:00:00', 'comment' => $who];
        }
        foreach (['parts', 'person'] as $mode) {
            $this->boot($existing);
            $this->post(self::command());
            if ($mode === 'person') {
                $this->press('fd_split:1');
            }
            $this->press('fd_acc:1:1');
            $this->press('fd_date:1:20261010');
            $this->press('fd_go:1');
            $this->assertSame([], $this->creates(), "режим {$mode}: всё уже внесено");
            $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
            $this->assertSame(['dup'], array_values(array_unique(array_column($recs, 'status'))));
            $this->assertSame('review', $this->repo->rows[1]['status']);
            $ids = array_column(array_merge(...array_column($recs, 'dup_of')), 'id');
            sort($ids);
            $this->assertSame([11895, 11896, 11897, 11898, 11899, 11900], $ids);
            $q = $this->poster->callsTo('finance.getTransactions')[0]['params'];
            $this->assertSame(['20261007', '20261013', 1], [$q['dateFrom'], $q['dateTo'], $q['account_id']]);
            $edit = $this->tg->callsTo('editMessageText');
            $this->assertStringContainsString('возможный дубль: #1189', end($edit)['params']['text']);
            $this->assertStringContainsString('Повторить?', end($edit)['params']['text']);
        }
    }

    public function test_partial_failure_resumes_without_duplicates(): void
    {
        $fail = true;
        $this->boot([], function (array $p) use (&$fail) {
            if ($p['amount_from'] === 3978800 && $fail) {
                // Явный отказ Poster (объект error в ответе) — запись точно не создана.
                throw new \Exception('Poster API Error: Account is blocked (http=200, method=finance.createTransactions)');
            }
            return 30000 + count($this->creates());
        });
        $this->post(self::command());
        $this->press('fd_acc:1:1');
        $this->press('fd_date:1:20261010');
        $this->press('fd_go:1');
        $this->assertSame('partial', $this->repo->rows[1]['status']);
        $this->assertCount(6, $this->creates(), '6 попыток, одна упала');

        $fail = false;
        $this->press('fd_go:1');
        $this->assertSame('done', $this->repo->rows[1]['status']);
        $amounts = array_map(fn($c) => $c['params']['amount_from'], $this->creates());
        $this->assertCount(7, $amounts, 'дослана только упавшая запись');
        $this->assertSame(3978800, end($amounts));
        $this->assertCount(2, array_keys($amounts, 3978800, true));
        $this->assertContains('aibot_fd_1', $this->lock->names);
    }

    public function test_refusals_fail_closed(): void
    {
        $this->boot();
        // другой участник
        $this->post(self::command(['from' => ['id' => self::OTHER, 'username' => 'other']]));
        // тот же username, другой id
        $this->post(self::command(['from' => ['id' => self::OTHER, 'username' => 'dmitry']]));
        // пересланная команда владельца
        $this->post(self::command(['forward_origin' => ['type' => 'user', 'sender_user' => ['id' => self::OWNER]], 'forward_date' => self::NOW - 40]));
        // цитата
        $this->post(self::command(['quote' => ['text' => 'внеси инвесторы']]));
        // старый апдейт (досланный после простоя)
        $this->post(self::command(['date' => self::NOW - 3600]));
        // не разрешённый чат
        $this->post(self::command(['chat' => ['id' => -999, 'type' => 'supergroup']]));
        $this->assertSame([], $this->repo->rows);
        $this->assertSame([], $this->tg->calls, 'ни одного ответа в Telegram');

        // пустые списки = никто
        $this->boot([], null, [], []);
        $this->post(self::command());
        $this->assertSame([], $this->repo->rows);
        $this->assertSame([], $this->tg->calls);
    }

    public function test_owner_id_accepted(): void
    {
        $this->boot();
        $this->post(self::command(['from' => ['id' => self::OWNER, 'username' => 'renamed']]));
        $this->assertCount(1, $this->repo->rows, 'решает id, а не username');
    }

    public function test_buttons_only_for_initiator(): void
    {
        $this->boot([], null, [self::OWNER, self::OTHER]);
        $this->post(self::command());
        $this->press('fd_acc:1:1', self::OTHER);
        $this->assertNull($this->repo->rows[1]['account_id'], 'другой id (даже из списка) не подтверждает чужой черновик');

        $this->boot();
        $this->post(self::command());
        $this->press('fd_acc:1:1');
        $this->press('fd_date:1:20261010');
        $this->press('fd_go:1', self::OTHER);
        $this->assertSame([], $this->creates());
        $ans = $this->tg->callsTo('answerCallbackQuery');
        $this->assertSame('Нет доступа', end($ans)['params']['text']);
        // кнопка не с этой карточки
        $this->press('fd_go:1', self::OWNER, 12345);
        $this->assertSame([], $this->creates());
    }

    public function test_non_trigger_and_bad_parts_block_execute(): void
    {
        $this->boot();
        $this->post(self::command(['text' => 'спасибо!']));
        $this->assertSame([], $this->repo->rows);

        $this->post(self::command([], 'Олег 1 000 000 (переводы 600 000 + 300 000)', 700));
        $this->press('fd_acc:1:1');
        $this->press('fd_date:1:20261010');
        $this->press('fd_go:1');
        $this->assertSame([], $this->creates());
        $this->assertSame('draft', $this->repo->rows[1]['status']);
    }

    public function test_date_out_of_range_rejected(): void
    {
        $this->boot();
        $this->post(self::command());
        $this->press('fd_date:1:20261011');
        $this->press('fd_date:1:20250101');
        $this->assertNull($this->repo->rows[1]['tx_date']);
    }

    // ─── ревью 23c87278 ───────────────────────────────────────────────────

    private function readyDraft(): void
    {
        $this->post(self::command());
        $this->press('fd_acc:1:1');
        $this->press('fd_date:1:20261010');
    }

    public function test_cancel_and_choices_after_execute_are_rejected(): void
    {
        $this->boot();
        $this->readyDraft();
        $this->press('fd_go:1');
        $this->press('fd_cancel:1');
        $this->assertSame('done', $this->repo->rows[1]['status'], 'после внесения отмена не действует');

        $this->boot();
        $this->readyDraft();
        $this->repo->update(1, ['status' => 'executing', 'heartbeat_at' => self::NOW]);
        $this->press('fd_acc:1:2');
        $this->press('fd_cancel:1');
        $this->assertSame(1, $this->repo->rows[1]['account_id']);
        $this->assertSame('executing', $this->repo->rows[1]['status']);
        $this->assertContains('aibot_fd_1', $this->lock->names, 'выбор идёт под локом черновика');
        $this->press('fd_go:1');
        $this->assertSame([], $this->creates(), 'свежий executing — второй прогон не запускается');
    }

    public function test_stale_executing_crash_needs_reconciliation(): void
    {
        $this->boot();
        $this->readyDraft();
        $this->press('fd_go:1');
        $this->assertCount(6, $this->creates());
        // Процесс «умер» посреди: последняя запись в sending, executing 5 минут без движения.
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $recs[5]['status'] = 'sending';
        $recs[5]['tx_ids'] = [];
        $this->repo->update(1, ['status' => 'executing', 'heartbeat_at' => self::NOW - 300,
            'poster_tx_ids_json' => json_encode($recs, JSON_UNESCAPED_UNICODE)]);
        // InMemoryAuditLog не знает времени; в проде 10-секундное окно анти-даблклика давно прошло.
        $this->audit->rows = [];

        $card = $this->ctlRender();
        $this->assertContains('fd_go:1', array_column(array_merge(...$card['keyboard']), 'callback_data'), 'кнопка «Повторить»');

        // Исход записи в sending неизвестен — повторно не шлём, нужна сверка.
        $this->press('fd_go:1');
        $this->assertSame('reconcile', $this->repo->rows[1]['status']);
        $this->assertCount(6, $this->creates(), 'ничего не дослано');
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $this->assertSame('unverified', $recs[5]['status']);
        $this->assertSame(['done'], array_values(array_unique(array_column(array_slice($recs, 0, 5), 'status'))));
    }

    private function ctlRender(): array
    {
        $svc = (new \ReflectionProperty($this->ctl, 'drafts'))->getValue($this->ctl);
        return $svc->render($this->repo->rows[1]);
    }

    public function test_deferred_job_failure_is_logged_and_reported(): void
    {
        $this->boot();
        $this->repo->failInsert = true;
        $this->assertSame('ok', $this->post(self::command()));
        $this->assertContains('aibot.error', array_column($this->logs, 'message'));
        $send = $this->tg->callsTo('sendMessage');
        $this->assertCount(1, $send);
        $this->assertStringContainsString('Не удалось', $send[0]['params']['text']);
    }

    public function test_bad_reply_targets_get_a_hint(): void
    {
        $cases = [
            'no text' => ['message_id' => 500, 'date' => self::NOW, 'from' => ['id' => 333], 'photo' => [['file_id' => 'x']]],
            'topic header' => ['message_id' => 500, 'date' => self::NOW, 'from' => ['id' => 333], 'text' => PayoutParserTest::IGOR,
                'forum_topic_created' => ['name' => 'Финансы']],
            'bot' => ['message_id' => 500, 'date' => self::NOW, 'from' => ['id' => 999, 'is_bot' => true], 'text' => PayoutParserTest::IGOR],
        ];
        foreach ($cases as $name => $reply) {
            $this->boot();
            $this->post(self::command(['reply_to_message' => $reply]));
            $this->assertSame([], $this->repo->rows, $name);
            $send = $this->tg->callsTo('sendMessage');
            $this->assertCount(1, $send, $name);
            $this->assertMatchesRegularExpression('/Ответьте|нет текста/u', $send[0]['params']['text'], $name);
        }
    }

    public function test_llm_names_are_sanitized_and_capped(): void
    {
        $this->extractor = new class implements PayoutExtractorInterface {
            public function isAvailable(): bool { return true; }
            public function extract(string $sourceText): array
            {
                $out = [PayoutParser::row('Ол😀ег' . str_repeat('я', 80), 100000, [])];
                for ($i = 0; $i < 35; $i++) {
                    $out[] = PayoutParser::row('Имя' . $i, 1000, []);
                }
                return $out;
            }
        };
        $this->boot();
        $this->post(self::command([], 'выплаты как договорились', 800));
        $data = json_decode($this->repo->rows[1]['rows_json'], true);
        $this->assertCount(30, $data['people']);
        $this->assertStringStartsWith('Олег', $data['people'][0]['name']);
        $this->assertSame(60, mb_strlen($data['people'][0]['name']));
        $this->assertNotSame([], $data['errors'], 'больше 30 строк — ошибка, «Внести» заблокирована');
        $this->press('fd_acc:1:1');
        $this->press('fd_date:1:20261010');
        $this->press('fd_go:1');
        $this->assertSame([], $this->creates());
    }

    public function test_account_revalidated_at_execute(): void
    {
        $this->boot();
        $this->readyDraft();
        $this->repo->update(1, ['account_id' => 99]); // счёт убрали из настроек payday
        $this->press('fd_go:1');
        $this->assertSame([], $this->creates());
        $this->assertSame('draft', $this->repo->rows[1]['status']);
    }

    // ─── возможные дубли: сумма + имя + счёт + кат. 22 ± 3 дня ───────────────

    /** @var list<array<string,mixed>> живая «книга» Poster: создания попадают сюда */
    private array $ledger = [];

    private function bootLedger(array $existing): void
    {
        $this->ledger = $existing;
        $this->boot([], function (array $p) {
            $id = ++$this->nextTx;
            $this->ledger[] = ['transaction_id' => $id, 'account_id' => $p['account_from'], 'category_id' => $p['category'],
                'type' => 0, 'amount' => (string) (-$p['amount_from'] * 100), 'date' => $p['date'], 'comment' => $p['comment']];
            return $id;
        });
        $this->poster->responses['finance.getTransactions'] = fn(array $q) => $this->ledger;
    }

    private static function tx(int $id, int $vnd, string $comment, int $acc = 1, int $cat = 22): array
    {
        return ['transaction_id' => $id, 'account_id' => $acc, 'category_id' => $cat, 'type' => 0,
            'amount' => (string) (-$vnd * 100), 'date' => '2026-10-09 15:00:00', 'comment' => $comment];
    }

    private function runSingle(string $source): void
    {
        $this->post(self::command([], $source));
        $this->press('fd_acc:1:1');
        $this->press('fd_date:1:20261010');
        $this->press('fd_go:1');
    }

    private function decisions(): array
    {
        return array_values(array_filter($this->audit->rows, fn($r) => $r['action'] === 'aibot.finance_draft.duplicate_decision'));
    }

    public function test_equal_payout_same_person_asks_and_repeats_on_confirm(): void
    {
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->assertSame([], $this->creates(), 'возможный дубль по умолчанию не вносится');
        $this->assertSame('review', $this->repo->rows[1]['status']);
        $journal = array_values(array_filter($this->tg->callsTo('sendMessage'), fn($c) => str_contains($c['params']['text'], '📒')));
        $this->assertCount(1, $journal, 'бот пишет владельцу о дубле');
        $this->assertStringContainsString('возможный дубль: #9001 от 09.10.2026, 1 000 000, «Олег». Повторить?', $journal[0]['params']['text']);
        $card = $this->ctlRender();
        $this->assertSame(['fd_rep:1:0', 'fd_skip:1:0'], array_column($card['keyboard'][0], 'callback_data'));

        $this->audit->rows = [];
        $this->press('fd_rep:1:0');
        $c = $this->creates();
        $this->assertCount(1, $c, 'явное разрешение → ровно один повтор');
        $this->assertSame(1000000, $c[0]['params']['amount_from']);
        $this->assertSame('done', $this->repo->rows[1]['status']);
        $d = $this->decisions();
        $this->assertCount(1, $d);
        $this->assertSame('repeat', $d[0]['payload']['decision']);
        $this->assertSame('tg:' . self::OWNER, $d[0]['email']);
        $this->assertSame(9001, $d[0]['payload']['dup_of'][0]['id']);

        // Повтор callback, повторное «Внести», повторный триггер — новых записей нет.
        $this->press('fd_rep:1:0');
        $this->press('fd_go:1');
        $this->post(self::command(['message_id' => 601], 'Выплаты: Олег 1 000 000'));
        $this->assertCount(1, $this->creates());
        $this->assertCount(1, $this->decisions(), 'повторный callback не пишет второе решение');
    }

    public function test_equal_payout_same_person_declined_is_not_entered(): void
    {
        $this->bootLedger([self::tx(9001, 1000000, 'Дивиденды август — Олег')]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->press('fd_skip:1:0');
        $this->assertSame([], $this->creates());
        $this->assertSame('done', $this->repo->rows[1]['status']);
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $this->assertSame('skipped', $recs[0]['status']);
        $d = $this->decisions();
        $this->assertCount(1, $d);
        $this->assertSame('skip', $d[0]['payload']['decision']);
        // Передумать после отказа нельзя: решение уже принято.
        $this->press('fd_rep:1:0');
        $this->press('fd_go:1');
        $this->assertSame([], $this->creates());
    }

    public function test_equal_amount_other_person_is_not_a_duplicate(): void
    {
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $this->runSingle('Выплаты: Дима 1 000 000');
        $this->assertCount(1, $this->creates());
        $this->assertSame('done', $this->repo->rows[1]['status']);
    }

    public function test_name_must_match_as_a_whole_word(): void
    {
        // «Ли» не совпадает с «Ливия».
        $this->bootLedger([self::tx(9001, 1000000, 'Ливия, оплата')]);
        $this->runSingle('Выплаты: Ли 1 000 000');
        $this->assertCount(1, $this->creates());
    }

    public function test_other_account_or_category_is_not_a_duplicate(): void
    {
        $this->bootLedger([self::tx(9001, 1000000, 'Олег', 2), self::tx(9002, 1000000, 'Олег', 1, 5)]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->assertCount(1, $this->creates());
        $this->assertSame('done', $this->repo->rows[1]['status']);
    }

    public function test_approved_repeat_crash_retry_does_not_double(): void
    {
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->press('fd_rep:1:0');
        $this->assertCount(1, $this->creates());
        // Процесс «умер» сразу после отправки: запись в sending, исход неизвестен,
        // но в Poster транзакция уже есть (ledger).
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $recs[0]['status'] = 'sending';
        $recs[0]['tx_ids'] = [];
        $this->repo->update(1, ['status' => 'executing', 'heartbeat_at' => self::NOW - 300,
            'poster_tx_ids_json' => json_encode($recs, JSON_UNESCAPED_UNICODE)]);
        $this->audit->rows = [];
        $this->press('fd_go:1');
        $this->assertCount(1, $this->creates(), 'после краша повторно не отправлено');
        $this->assertUnverified([20001]);
    }

    public function test_duplicate_decision_by_other_user_is_denied(): void
    {
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->press('fd_rep:1:0', self::OTHER);
        $this->press('fd_skip:1:0', self::OTHER);
        $this->assertSame([], $this->creates());
        $this->assertSame('review', $this->repo->rows[1]['status']);
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $this->assertSame('dup', $recs[0]['status']);
        $this->assertSame([], $this->decisions());
    }

    public function test_crash_after_send_needs_reconciliation_not_resend(): void
    {
        // Обычная (не дубль) запись: Poster создал транзакцию, а мы упали до записи
        // статуса. Однозначно «своей» её не назвать — нужна сверка, повтора нет.
        $this->bootLedger([]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->assertCount(1, $this->creates());
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $recs[0]['status'] = 'sending';
        $recs[0]['tx_ids'] = [];
        $this->repo->update(1, ['status' => 'executing', 'heartbeat_at' => self::NOW - 300,
            'poster_tx_ids_json' => json_encode($recs, JSON_UNESCAPED_UNICODE)]);
        $this->audit->rows = [];
        $this->press('fd_go:1');
        $this->assertCount(1, $this->creates());
        $this->assertUnverified([20001]);
    }

    public function test_approved_repeat_failed_but_created_is_not_resent(): void
    {
        // Повтор одобрен, Poster транзакцию создал, но ответ — ошибка (таймаут).
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $create = $this->poster->responses['finance.createTransactions'];
        $this->poster->responses['finance.createTransactions'] = function (array $p) use ($create) {
            $create($p);
            throw new \RuntimeException('timeout');
        };
        $this->press('fd_rep:1:0');
        $this->assertCount(1, $this->creates());
        // Таймаут — исход неизвестен: сразу «нужна сверка», не partial.
        $this->assertSame('reconcile', $this->repo->rows[1]['status']);
        $this->poster->responses['finance.createTransactions'] = $create;
        $this->audit->rows = [];
        $this->press('fd_go:1');
        $this->assertCount(1, $this->creates(), 'исход не определён — второй раз не шлём');
        $this->assertUnverified([20001]);
    }

    public function test_stale_repeat_callback_does_not_resend_failed_records(): void
    {
        // Две строки: одна — возможный дубль, другая упала при отправке.
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $create = $this->poster->responses['finance.createTransactions'];
        // Явный отказ Poster — строка Дима failed (rejected), черновик partial.
        $this->poster->responses['finance.createTransactions'] = fn(array $p) => throw new \Exception('Poster API Error: Access denied (http=200, method=finance.createTransactions)');
        $this->runSingle('Выплаты: Олег 1 000 000, Дима 2 000 000');
        $this->assertSame('partial', $this->repo->rows[1]['status']);
        $this->poster->responses['finance.createTransactions'] = $create;
        // Запоздавшее нажатие «Повторить» по дублю при partial — решение не принято, ничего не шлём.
        $this->press('fd_rep:1:0');
        $this->assertSame([], $this->successfulCreates());
        $this->assertSame('partial', $this->repo->rows[1]['status']);
        $this->assertSame([], $this->decisions());
    }

    private function successfulCreates(): array
    {
        return array_values(array_filter($this->ledger, fn($t) => (int) $t['transaction_id'] > 20000));
    }

    public function test_audit_failure_blocks_repeat(): void
    {
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->audit->failOn = 'aibot.finance_draft.duplicate_decision';
        $this->press('fd_rep:1:0');
        $this->assertSame([], $this->successfulCreates());
        $this->assertSame('review', $this->repo->rows[1]['status']);
    }

    public function test_repeat_button_without_index_is_rejected(): void
    {
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->press('fd_rep:1');
        $this->assertSame([], $this->successfulCreates());
        $this->assertSame([], $this->decisions());
    }

    public function test_approved_repeat_with_altered_comment_needs_reconciliation(): void
    {
        // Повтор одобрен, Poster создал транзакцию, но вернул ошибку и сохранил
        // комментарий изменённым — второй раз не шлём, просим сверку.
        $this->bootLedger([self::tx(9001, 1000000, 'Олег')]);
        $this->runSingle('Выплаты: Олег 1 000 000');
        $create = $this->poster->responses['finance.createTransactions'];
        $this->poster->responses['finance.createTransactions'] = function (array $p) use ($create) {
            $create($p);
            $last = array_key_last($this->ledger);
            $this->ledger[$last]['comment'] = 'ДИВИДЕНДЫ  -  ОЛЕГ (edited)';
            throw new \RuntimeException('timeout');
        };
        $this->press('fd_rep:1:0');
        $this->poster->responses['finance.createTransactions'] = $create;
        $this->audit->rows = [];
        $this->press('fd_go:1');
        $this->assertCount(1, $this->successfulCreates());
        $this->assertUnverified([20001]);
    }

    /** Запись «нужна сверка»: не done, не отправлена, кандидаты показаны, черновик reconcile. */
    private function assertUnverified(array $candidateIds): void
    {
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $this->assertSame('unverified', $recs[0]['status']);
        $this->assertSame([], $recs[0]['tx_ids'] ?? []);
        $this->assertSame($candidateIds, array_column($recs[0]['candidates'], 'id'));
        $this->assertSame('reconcile', $this->repo->rows[1]['status']);
        $journal = array_values(array_filter($this->tg->callsTo('sendMessage'), fn($c) => str_contains($c['params']['text'], '📒')));
        $this->assertStringContainsString('нужна сверка', end($journal)['params']['text']);
        $this->assertSame([], $this->ctlRender()['keyboard'], 'кнопки повторной отправки нет');
        // Ещё одно «Внести» тоже ничего не шлёт.
        $before = count($this->creates());
        $this->press('fd_go:1');
        $this->assertCount($before, $this->creates());
    }

    public function test_failed_repeat_with_other_equal_payout_is_not_taken_as_own(): void
    {
        // Повтор одобрен (исходная #9001), отправка упала и в Poster НЕ дошла.
        // Но в Poster есть другая равная выплата тому же получателю (#9002) вне dup_of.
        $this->bootLedger([self::tx(9001, 1000000, 'Олег'), self::tx(9002, 1000000, 'Олег')]);
        $this->post(self::command([], 'Выплаты: Олег 1 000 000'));
        $this->press('fd_acc:1:1');
        $this->press('fd_date:1:20261010');
        $this->press('fd_go:1');
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $this->assertSame('dup', $recs[0]['status']);
        $this->assertSame([9001], array_column($recs[0]['dup_of'], 'id'));

        $create = $this->poster->responses['finance.createTransactions'];
        $this->poster->responses['finance.createTransactions'] = fn(array $p) => throw new \RuntimeException('timeout');
        $this->press('fd_rep:1:0');
        $this->assertSame([], $this->successfulCreates(), 'в Poster своей записи нет');
        $this->poster->responses['finance.createTransactions'] = $create;
        $this->audit->rows = [];

        $this->press('fd_go:1');
        $this->assertSame([], $this->successfulCreates(), 'не отправлено');
        $this->assertUnverified([9002]);
    }

    public function test_proven_rejection_is_resent(): void
    {
        // Poster явно отказал (error в ответе) — запись не создана, повтор допустим.
        $this->bootLedger([]);
        $create = $this->poster->responses['finance.createTransactions'];
        $this->poster->responses['finance.createTransactions'] = fn(array $p) => throw new \Exception('Poster API Error: Access denied (http=200, method=finance.createTransactions)');
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->assertSame('partial', $this->repo->rows[1]['status']);
        $this->assertSame('rejected', json_decode($this->repo->rows[1]['poster_tx_ids_json'], true)[0]['outcome']);
        $this->poster->responses['finance.createTransactions'] = $create;
        $this->audit->rows = [];
        $this->press('fd_go:1');
        $this->assertCount(1, $this->successfulCreates());
        $this->assertSame('done', $this->repo->rows[1]['status']);
    }

    public function test_duplicate_decision_only_by_owner_even_if_in_allow_list(): void
    {
        // В allow-list двое, команду дал второй; решать по дублю может только владелец.
        $this->boot([], null, [self::OWNER, self::OTHER], [self::CHAT], self::OWNER);
        $this->ledger = [self::tx(9001, 1000000, 'Олег')];
        $this->poster->responses['finance.getTransactions'] = fn(array $q) => $this->ledger;
        $this->post(self::command(['from' => ['id' => self::OTHER, 'username' => 'x']], 'Выплаты: Олег 1 000 000'));
        $this->press('fd_acc:1:1', self::OTHER);
        $this->press('fd_date:1:20261010', self::OTHER);
        $this->press('fd_go:1', self::OTHER);
        $this->assertSame('review', $this->repo->rows[1]['status']);
        $this->press('fd_rep:1:0', self::OTHER);
        $this->press('fd_skip:1:0', self::OTHER);
        $this->assertSame([], $this->creates());
        $this->assertSame([], $this->decisions());
        $this->assertSame('dup', json_decode($this->repo->rows[1]['poster_tx_ids_json'], true)[0]['status']);
        $this->assertContains('aibot.callback.dup_not_owner', array_column($this->logs, 'message'));

        // Владелец решает и по черновику, который начал другой — черновик не зависает.
        $this->press('fd_rep:1:0', self::OWNER);
        $this->assertCount(1, $this->creates());
        $this->assertSame('done', $this->repo->rows[1]['status']);
        $this->assertSame('tg:' . self::OWNER, $this->decisions()[0]['email']);
    }

    public function test_default_duplicate_approver_is_the_owner_id(): void
    {
        $cfg = new AiBotConfig([169510539, 555], ['-1']);
        $this->assertSame(169510539, AiBotConfig::OWNER_TG_ID);
        $this->assertTrue($cfg->canDecideDuplicate(169510539));
        $this->assertFalse($cfg->canDecideDuplicate(555), 'в allow-list, но не владелец');
        $this->assertFalse((new AiBotConfig([555], ['-1']))->canDecideDuplicate(169510539), 'владелец вне allow-list — тоже нет');
    }

    /** Отправка с неизвестным исходом → «нужна сверка», без повторной отправки (кандидатов может не быть). */
    private function assertReconcileNoResend(int $expectedCreates): void
    {
        $recs = json_decode($this->repo->rows[1]['poster_tx_ids_json'], true);
        $this->assertSame('unverified', $recs[0]['status'], 'не done');
        $this->assertSame([], $recs[0]['tx_ids'] ?? []);
        $this->assertSame('reconcile', $this->repo->rows[1]['status']);
        $this->assertCount($expectedCreates, $this->creates(), 'лишняя запись не создана');
        $journal = array_values(array_filter($this->tg->callsTo('sendMessage'), fn($c) => str_contains($c['params']['text'], '📒')));
        $this->assertStringContainsString('результат не определён, нужна сверка', end($journal)['params']['text']);
        $this->press('fd_go:1');
        $this->assertCount($expectedCreates, $this->creates(), 'и повторное «Внести» ничего не шлёт');
    }

    public function test_lost_response_with_empty_read_needs_reconciliation(): void
    {
        // create прошёл в Poster, ответ потерян (таймаут), а следующий
        // getTransactions ещё пустой — это НЕ доказательство, что записи нет.
        $this->bootLedger([]);
        $this->poster->responses['finance.createTransactions'] = fn(array $p) => throw new \Exception('CURL Error: Operation timed out after 15000 milliseconds');
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->assertSame('reconcile', $this->repo->rows[1]['status']);
        $this->assertSame([], $this->ledger, 'чтение пустое');
        $this->audit->rows = [];
        $this->assertReconcileNoResend(1);
        $this->assertSame([], json_decode($this->repo->rows[1]['poster_tx_ids_json'], true)[0]['candidates']);
    }

    public function test_created_with_fully_changed_comment_needs_reconciliation(): void
    {
        // Запись создана, но её комментарий в Poster полностью другой (без имени),
        // а ответ потерян. Кандидатов по имени/комментарию нет — всё равно сверка.
        $this->bootLedger([]);
        $this->poster->responses['finance.createTransactions'] = function (array $p) {
            $this->ledger[] = ['transaction_id' => 30001, 'account_id' => $p['account_from'], 'category_id' => $p['category'],
                'type' => 0, 'amount' => (string) (-$p['amount_from'] * 100), 'date' => $p['date'], 'comment' => 'корректировка'];
            throw new \Exception('Poster API Error: empty response (http=0, method=finance.createTransactions)');
        };
        $this->runSingle('Выплаты: Олег 1 000 000');
        $this->audit->rows = [];
        $this->assertReconcileNoResend(1);
        $this->assertCount(1, $this->ledger, 'в Poster ровно одна запись');
    }

    public function test_failure_classification(): void
    {
        $m = new \ReflectionMethod(FinanceDraftService::class, 'isProvenRejection');
        $wrap = fn(string $msg) => new \RuntimeException('Poster: ' . $msg, 0, new \Exception($msg));
        // Доказанный отказ — повтор допустим.
        $this->assertTrue($m->invoke(null, new \InvalidArgumentException('Invalid amount')));
        $this->assertTrue($m->invoke(null, new \DomainException('Операция уже выполняется в другой вкладке — повторите через несколько секунд.')));
        // «Только что создана» — предыдущая попытка успела создать: исход неизвестен.
        $this->assertFalse($m->invoke(null, new \DomainException('Такая же транзакция только что создана — повтор отклонён.')));
        // Подделка в тексте (комментарий внутри params=) не делает таймаут «отказом».
        $this->assertFalse($m->invoke(null, $wrap('Poster API Error: http=502 method=finance.createTransactions params={"comment":"CURL Error: Failed to connect"} body=')));
        $this->assertTrue($m->invoke(null, $wrap('CURL Error: Could not resolve host: joinposter.com')));
        $this->assertTrue($m->invoke(null, $wrap('CURL Error: Failed to connect to joinposter.com port 443')));
        $this->assertTrue($m->invoke(null, $wrap('Poster API Error: Access denied (http=200, method=finance.createTransactions)')));
        // Исход неизвестен — только сверка.
        $this->assertFalse($m->invoke(null, $wrap('CURL Error: Operation timed out after 15000 milliseconds')));
        $this->assertFalse($m->invoke(null, $wrap('Poster API Error: http=502 method=finance.createTransactions params={} body=')));
        $this->assertFalse($m->invoke(null, $wrap('Poster API Error: empty response (http=0, method=finance.createTransactions)')));
        $this->assertFalse($m->invoke(null, $wrap('JSON Decode Error: Syntax error')));
        $this->assertFalse($m->invoke(null, new \RuntimeException('timeout')));
    }

    public function test_stale_executing_with_everything_settled_advances_status(): void
    {
        // Процесс умер после последней записи (все done), итог не записан.
        $this->boot();
        $this->readyDraft();
        $this->press('fd_go:1');
        $this->assertCount(6, $this->creates());
        $this->repo->update(1, ['status' => 'executing', 'heartbeat_at' => self::NOW - 300]);
        $before = count($this->poster->callsTo('finance.getTransactions'));
        $this->press('fd_go:1');
        $this->assertSame('done', $this->repo->rows[1]['status']);
        $this->assertCount(6, $this->creates());
        $this->assertCount($before, $this->poster->callsTo('finance.getTransactions'), 'без лишнего вызова Poster');
    }
}
