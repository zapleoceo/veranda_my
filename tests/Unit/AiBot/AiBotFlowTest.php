<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot;

use App\AiBot\AiBotConfig;
use App\AiBot\AiBotWebhookController;
use App\AiBot\FinanceDraftService;
use App\AiBot\PayoutParser;
use App\Infrastructure\TelegramBotClient;
use App\Payday3\Contracts\PosterLookupServiceInterface;
use App\Payday3\Services\PosterTransactionCreateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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
    private int $nextTx = 20000;

    /** @param list<array<string,mixed>> $existing ответ finance.getTransactions */
    public function boot(array $existing = [], ?\Closure $create = null, array $owners = [self::OWNER], array $chats = [self::CHAT]): void
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
            new AiBotConfig($owners, $chats),
            $svc,
            new TelegramBotClient('test-token', $this->tg),
            new PayoutParser(),
            null,
            new NullLogger(),
            $clock,
            static fn(\Closure $j) => $j(),
        );
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
        foreach ([11895 => 5000000, 11896 => 378800, 11897 => 3693200, 11898 => 3978800, 11899 => 4057200, 11900 => 2436000] as $id => $vnd) {
            $existing[] = ['transaction_id' => $id, 'account_id' => 1, 'category_id' => 22, 'type' => 0,
                'amount' => (string) (-$vnd * 100), 'date' => '2026-10-09 15:00:00'];
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
            $ids = array_merge(...array_column($recs, 'tx_ids'));
            sort($ids);
            $this->assertSame([11895, 11896, 11897, 11898, 11899, 11900], $ids);
            $q = $this->poster->callsTo('finance.getTransactions')[0]['params'];
            $this->assertSame(['20261007', '20261013', 1], [$q['dateFrom'], $q['dateTo'], $q['account_id']]);
            $edit = $this->tg->callsTo('editMessageText');
            $this->assertStringContainsString('уже внесено (#11895', end($edit)['params']['text']);
        }
    }

    public function test_partial_failure_resumes_without_duplicates(): void
    {
        $fail = true;
        $this->boot([], function (array $p) use (&$fail) {
            if ($p['amount_from'] === 3978800 && $fail) {
                throw new \RuntimeException('Poster 500');
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
}
