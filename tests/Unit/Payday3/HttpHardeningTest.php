<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Payday3\Domain\DateRange;
use App\Payday3\Http\Actions\BalanceScreenshotAction;
use App\Payday3\Http\Actions\ClearDayAction;
use App\Payday3\Contracts\DayResetServiceInterface;
use App\Payday3\Http\JsonResponder;
use App\Payday3\Http\TooManyRequestsException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * HTTP-слой payday3 после аудита: лимит периода, единый маппинг
 * исключений (без сырого текста SQLSTATE/IMAP наружу), проверка
 * скриншота для Telegram.
 */
final class HttpHardeningTest extends TestCase
{
    private function body(Response|\Psr\Http\Message\ResponseInterface $r): array
    {
        return json_decode((string)$r->getBody(), true) ?? [];
    }

    // ─── DateRange span guard ───────────────────────────────────────────

    public function test_days_counts_inclusive(): void
    {
        $this->assertSame(1, DateRange::of('2026-09-18', '2026-09-18')->days());
        $this->assertSame(31, DateRange::of('2026-08-01', '2026-08-31')->days());
    }

    public function test_read_range_is_capped_at_31_days(): void
    {
        $this->assertSame('2026-08-01', DateRange::forRead(['dateFrom' => '2026-08-01', 'dateTo' => '2026-08-31'])->from);
        $this->expectException(\InvalidArgumentException::class);
        DateRange::forRead(['dateFrom' => '2000-01-01', 'dateTo' => '2099-12-31']);
    }

    public function test_destructive_range_is_single_day(): void
    {
        $this->assertTrue(DateRange::forDestructive(['date' => '2026-09-18'])->isSingleDay());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('одним днём');
        DateRange::forDestructive(['dateFrom' => '2026-09-17', 'dateTo' => '2026-09-18']);
    }

    public function test_clear_day_with_wide_range_is_400_and_does_not_touch_data(): void
    {
        $reset = $this->createMock(DayResetServiceInterface::class);
        $reset->expects($this->never())->method('softReset');
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/payday3/api/day/clear')
            ->withQueryParams(['dateFrom' => '2000-01-01', 'dateTo' => '2099-12-31']);

        $res = (new ClearDayAction($reset))($req, new Response());
        $this->assertSame(400, $res->getStatusCode());
        $this->assertFalse($this->body($res)['ok']);
    }

    // ─── JsonResponder::fromException ───────────────────────────────────

    public function test_domain_messages_are_user_facing_400(): void
    {
        $r = JsonResponder::fromException(new Response(), new \DomainException('Сумма = 0'));
        $this->assertSame(400, $r->getStatusCode());
        $this->assertSame('Сумма = 0', $this->body($r)['error']);

        $r = JsonResponder::fromException(new Response(), new \InvalidArgumentException('Bad date'));
        $this->assertSame('Bad date', $this->body($r)['error']);
    }

    public function test_infrastructure_errors_are_logged_not_leaked(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'pd3log');
        $prev = ini_set('error_log', $log);
        try {
            $r = JsonResponder::fromException(new Response(),
                new \PDOException('SQLSTATE[42S02]: table payday3.secret missing'), 500, 'payday3.test');
        } finally {
            ini_set('error_log', (string)$prev);
        }
        $this->assertSame(500, $r->getStatusCode());
        $err = $this->body($r)['error'];
        $this->assertSame(JsonResponder::GENERIC_ERROR, $err);
        $this->assertStringNotContainsString('SQLSTATE', $err);
        $this->assertStringContainsString('SQLSTATE[42S02]', (string)file_get_contents($log), 'details go to the log');
        @unlink($log);
    }

    public function test_upstream_status_gets_upstream_message(): void
    {
        $prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        try {
            $r = JsonResponder::fromException(new Response(), new \Exception('Poster API Error: params={"token":…}'), 502);
        } finally {
            ini_set('error_log', (string)$prev);
        }
        $this->assertSame(502, $r->getStatusCode());
        $this->assertSame(JsonResponder::UPSTREAM_ERROR, $this->body($r)['error']);
    }

    public function test_throttle_maps_to_429(): void
    {
        $r = JsonResponder::fromException(new Response(), new TooManyRequestsException('Слишком часто', 3));
        $this->assertSame(429, $r->getStatusCode());
        $this->assertSame('3', $r->getHeaderLine('Retry-After'));
    }

    // ─── Screenshot validation ──────────────────────────────────────────

    private static function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    public function test_real_png_is_accepted(): void
    {
        if (!function_exists('imagecreatetruecolor')) $this->markTestSkipped('gd not available');
        $bytes = self::png();
        $this->assertSame($bytes, BalanceScreenshotAction::decodeImage('data:image/png;base64,' . base64_encode($bytes)));
    }

    public function test_non_image_with_image_prefix_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BalanceScreenshotAction::decodeImage('data:image/png;base64,' . base64_encode('<?php echo 1; ?>'));
    }

    public function test_wrong_prefix_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BalanceScreenshotAction::decodeImage('data:image/svg+xml;base64,' . base64_encode('<svg/>'));
    }

    public function test_oversized_payload_is_rejected_before_decoding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('5 МБ');
        BalanceScreenshotAction::decodeImage('data:image/png;base64,' . str_repeat('A', BalanceScreenshotAction::MAX_BYTES * 2));
    }

    public function test_throttle_blocks_second_call_within_cooldown(): void
    {
        $_SESSION = [];
        \App\Payday3\Http\RequestThrottle::guard('unit-test', 5);
        try {
            \App\Payday3\Http\RequestThrottle::guard('unit-test', 5);
            $this->fail('second call must be throttled');
        } catch (TooManyRequestsException $e) {
            $this->assertGreaterThan(0, $e->retryAfter);
        } finally {
            $_SESSION = [];
        }
    }
}
