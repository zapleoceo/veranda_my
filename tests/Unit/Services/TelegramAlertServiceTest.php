<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Infrastructure\Database;
use App\Infrastructure\TelegramBotClient;
use App\Models\AlertItem;
use App\Repositories\AlertItemRepository;
use App\Repositories\MetaRepository;
use App\Services\TelegramAlertService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TelegramAlertServiceTest extends TestCase
{
    private Database&MockObject            $db;
    private TelegramBotClient&MockObject   $bot;
    private MetaRepository&MockObject      $meta;
    private AlertItemRepository&MockObject $alertItems;

    protected function setUp(): void
    {
        $this->db         = $this->createMock(Database::class);
        $this->bot        = $this->createMock(TelegramBotClient::class);
        $this->meta       = $this->createMock(MetaRepository::class);
        $this->alertItems = $this->createMock(AlertItemRepository::class);
    }

    private function make(int|null $threadId = null): TelegramAlertService
    {
        return new TelegramAlertService(
            $this->db,
            $this->bot,
            $this->meta,
            $this->alertItems,
            $threadId
        );
    }

    public function test_new_overdue_item_sends_message(): void
    {
        $this->_mockSettings();
        $this->_mockMetrics(overdueRows: [
            [
                'id'                  => 42,
                'transaction_id'      => 100,
                'receipt_number'      => 'R001',
                'table_number'        => '5',
                'waiter_name'         => 'Ivan',
                'transaction_comment' => '',
                'dish_name'           => 'Pho Bo',
                'ticket_sent_at'      => date('Y-m-d H:i:s', time() - 1800),
            ],
        ]);

        // No existing alert for this item
        $this->alertItems->method('findByDate')->willReturn([]);

        // Expect a new message to be sent
        $this->bot->expects($this->once())
            ->method('sendMessageWithKeyboard')
            ->willReturn(999);

        $this->alertItems->expects($this->once())
            ->method('upsert')
            ->with($this->anything(), 42, 100, 999, $this->anything(), $this->anything());

        $this->meta->expects($this->atLeastOnce())->method('setMany');

        $this->make()->run();
    }

    public function test_item_no_longer_overdue_deletes_message(): void
    {
        $this->_mockSettings();
        // No overdue rows returned
        $this->_mockMetrics(overdueRows: []);

        $existingItem = new AlertItem(
            kitchenStatsId: 42,
            transactionId:  100,
            messageId:      777,
            lastTextHash:   'abc',
            lastSeenAt:     date('Y-m-d H:i:s'),
        );
        $this->alertItems->method('findByDate')->willReturn([42 => $existingItem]);

        $this->bot->expects($this->once())
            ->method('deleteMessage')
            ->with(777);

        $this->alertItems->expects($this->once())
            ->method('delete')
            ->with($this->anything(), 42);

        $this->meta->expects($this->atLeastOnce())->method('setMany');

        $this->make()->run();
    }

    public function test_unchanged_item_updates_seen_only(): void
    {
        $this->_mockSettings();

        $row = [
            'id' => 10, 'transaction_id' => 50,
            'receipt_number' => 'R10', 'table_number' => '3',
            'waiter_name' => 'Anna', 'transaction_comment' => '',
            'dish_name' => 'Spring Roll',
            'ticket_sent_at' => date('Y-m-d H:i:s', time() - 1500),
        ];
        $this->_mockMetrics(overdueRows: [$row]);

        // Pre-compute the hash the service would generate
        [$text, $keyboard] = $this->_buildHash($row);
        $hash = sha1($text . '|' . json_encode($keyboard));

        $existingItem = new AlertItem(10, 50, 123, $hash, date('Y-m-d H:i:s', time() - 120));
        $this->alertItems->method('findByDate')->willReturn([10 => $existingItem]);

        // Same hash → should NOT call sendMessageWithKeyboard or editMessageText
        $this->bot->expects($this->never())->method('sendMessageWithKeyboard');
        $this->bot->expects($this->never())->method('editMessageText');
        $this->alertItems->expects($this->once())->method('updateSeen');

        $this->meta->expects($this->atLeastOnce())->method('setMany');

        $this->make()->run();
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function _mockSettings(): void
    {
        $this->meta->method('getMany')->willReturn([
            'alert_timing_low_load'      => '20',
            'alert_load_threshold'       => '25',
            'alert_timing_high_load'     => '30',
            'exclude_partners_from_load' => '0',
            'ko_use_logical_close'       => '1',
        ]);
        $this->meta->method('get')->willReturn('');

        // Mock GET_LOCK → returns 1 (acquired)
        $lockStmt = $this->createMock(\PDOStatement::class);
        $lockStmt->method('fetchColumn')->willReturn('1');
        $releaseStmt = $this->createMock(\PDOStatement::class);

        $this->db->method('t')->willReturnCallback(fn($name) => $name);
    }

    private function _mockMetrics(array $overdueRows): void
    {
        // fetchColumn calls for open check counts → return 5
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('fetchColumn')->willReturn(5);

        // fetchAll for queue/overdue stations → return empty
        $stationStmt = $this->createMock(\PDOStatement::class);
        $stationStmt->method('fetchAll')->willReturn([]);

        // fetchAll for overdue rows → return test data
        $overdueStmt = $this->createMock(\PDOStatement::class);
        $overdueStmt->method('fetchAll')->willReturn($overdueRows);

        // GET_LOCK stmt
        $lockStmt = $this->createMock(\PDOStatement::class);
        $lockStmt->method('fetchColumn')->willReturn('1');

        $this->db->method('query')->willReturnCallback(
            function (string $sql) use ($countStmt, $stationStmt, $overdueStmt, $lockStmt) {
                if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
                    return $lockStmt;
                }
                if (str_contains($sql, 'COUNT(DISTINCT')) {
                    return $countStmt;
                }
                if (str_contains($sql, 'GROUP BY station')) {
                    return $stationStmt;
                }
                if (str_contains($sql, 'ticket_sent_at <')) {
                    return $overdueStmt;
                }
                // Default stmt for UPDATE, INSERT etc.
                return $this->createMock(\PDOStatement::class);
            }
        );

        $this->bot->method('editMessageText')->willReturn(false);
        $this->bot->method('sendMessageGetId')->willReturn(null);
        $this->bot->method('deleteMessage')->willReturn(true);
    }

    /** Replicate the text/keyboard building to compute expected hash */
    private function _buildHash(array $r): array
    {
        $receipt = trim((string) ($r['receipt_number'] ?? '')) ?: (string) ($r['transaction_id'] ?? '');
        $table   = trim((string) ($r['table_number']   ?? '')) ?: '—';
        $waiter  = trim((string) ($r['waiter_name']    ?? '')) ?: '—';
        $comment = trim((string) ($r['transaction_comment'] ?? ''));
        $dish    = trim((string) ($r['dish_name']      ?? '')) ?: '—';
        $sentAt  = trim((string) ($r['ticket_sent_at'] ?? ''));
        $sentTs  = $sentAt !== '' ? (int) strtotime($sentAt) : 0;
        $nowTs   = time();
        $diff    = $sentTs > 0 ? max(0, $nowTs - $sentTs) : 0;
        $hh = (int) floor($diff / 3600);
        $mm = (int) floor(($diff % 3600) / 60);
        $ss = (int) ($diff % 60);
        $elapsed = ($hh > 0 ? "{$hh}:" . str_pad((string) $mm, 2, '0', STR_PAD_LEFT) : str_pad((string) $mm, 2, '0', STR_PAD_LEFT)) . ':' . str_pad((string) $ss, 2, '0', STR_PAD_LEFT);
        $start = $sentTs > 0 ? date('H:i:s', $sentTs) : '—';

        $text  = '<b>Чек: ' . htmlspecialchars($receipt) . ' | Стол ' . htmlspecialchars($table) . "</b>\n";
        $text .= 'Офик: ' . htmlspecialchars($waiter);
        if ($comment !== '') $text .= ' <i>' . htmlspecialchars($comment) . '</i>';
        $text .= "\nБлюдо: " . htmlspecialchars($dish) . "\n";
        $text .= 'Старт: <b>' . htmlspecialchars($start) . '</b> Ждет: <b>' . $elapsed . '</b>';

        $kid  = (int) ($r['id'] ?? 0);
        $txId = (int) ($r['transaction_id'] ?? 0);
        $keyboard = [[
            ['text' => 'Игнор❗️',   'callback_data' => 'ignore_item:' . $kid],
            ['text' => 'Игнор Чек‼️', 'callback_data' => 'ignore_tx:'   . $txId],
        ]];

        return [$text, $keyboard];
    }
}
