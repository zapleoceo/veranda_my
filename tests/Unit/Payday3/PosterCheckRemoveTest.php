<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Contracts\TelegramNotifierInterface;
use App\Payday3\Services\PosterCheckService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Payday3\Fakes\FixedSettings;
use Tests\Unit\Payday3\Fakes\InMemoryAuditLog;
use Tests\Unit\Payday3\Fakes\InMemorySessionStore;
use Tests\Unit\Payday3\Fakes\ScriptedPoster;

/**
 * Удаление чека: раньше transactions.removeTransaction уходил для ЛЮБОГО id
 * (месячной давности, закрытого и оплаченного), а единственный след —
 * сообщение в Telegram, чат которого меняется в настройках.
 */
final class PosterCheckRemoveTest extends TestCase
{
    private InMemoryAuditLog $audit;

    private function service(ScriptedPoster $poster): PosterCheckService
    {
        $this->audit = new InMemoryAuditLog();
        $tg = $this->createMock(TelegramNotifierInterface::class);
        $tg->method('sendText')->willReturn(['ok' => true]);
        return new PosterCheckService($poster, $tg, FixedSettings::defaults(), new InMemorySessionStore(), $this->audit);
    }

    private function poster(mixed $check): ScriptedPoster
    {
        return new ScriptedPoster([
            'dash.getTransaction'           => $check,
            'transactions.removeTransaction' => ['err_code' => 0],
        ]);
    }

    public function test_recent_check_is_audited_then_removed(): void
    {
        $closedMs = (time() - 3600) * 1000;
        $poster = $this->poster([['transaction_id' => 42, 'date_close' => $closedMs, 'sum' => 49500000]]);

        $res = $this->service($poster)->remove(42, 'Op op@example.com', 'op@example.com');

        $this->assertTrue($res['ok']);
        $this->assertCount(1, $poster->callsTo('transactions.removeTransaction'));
        $this->assertCount(1, $this->audit->rows);
        $row = $this->audit->rows[0];
        $this->assertSame('op@example.com', $row['email']);
        $this->assertSame('poster_check.remove', $row['action']);
        $this->assertSame(42, $row['payload']['transaction_id']);
        $this->assertSame(495000, $row['payload']['sum']);
        // audit row is written BEFORE the destructive call
        $methods = array_column($poster->calls, 'method');
        $this->assertSame(['dash.getTransaction', 'transactions.removeTransaction'], $methods);
    }

    public function test_old_check_is_refused_and_nothing_is_deleted(): void
    {
        $poster = $this->poster(['transaction_id' => 42, 'date_close' => date('Y-m-d H:i:s', strtotime('-10 days'))]);
        try {
            $this->service($poster)->remove(42, 'Op', 'op@example.com');
            $this->fail('старый чек не должен удаляться');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('последние 3 дн', $e->getMessage());
        }
        $this->assertSame([], $poster->callsTo('transactions.removeTransaction'));
        $this->assertSame([], $this->audit->rows);
    }

    public function test_unknown_check_or_date_is_refused(): void
    {
        $poster = $this->poster([]);
        try {
            $this->service($poster)->remove(42, 'Op');
            $this->fail();
        } catch (\DomainException) {}

        $poster = $this->poster(['transaction_id' => 42, 'date_close' => '']);
        try {
            $this->service($poster)->remove(42, 'Op');
            $this->fail();
        } catch (\DomainException) {}
        $this->assertSame([], $poster->callsTo('transactions.removeTransaction'));
    }

    public function test_max_age_is_calendar_days(): void
    {
        $now = strtotime('2026-09-18 01:00:00');
        $this->assertTrue(PosterCheckService::isWithinMaxAge(strtotime('2026-09-15 00:05:00'), $now));
        $this->assertFalse(PosterCheckService::isWithinMaxAge(strtotime('2026-09-14 23:59:59'), $now));
        $this->assertTrue(PosterCheckService::isWithinMaxAge(strtotime('2026-09-18 00:30:00'), $now));
    }
}
