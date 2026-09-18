<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Classes\PosterAPI;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Domain\Actor;
use App\Payday3\Services\PosterTransactionCreateService;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Payday3\Fakes\FixedSettings;
use Tests\Unit\Payday3\Fakes\InMemoryAuditLog;
use Tests\Unit\Payday3\Fakes\PassThroughLock;

/**
 * Кнопка «+» в payday3: создание финансовой транзакции в Poster.
 *
 * В OUT (письма BIDV) она создаёт расход, в IN (поступления SePay) — приход.
 * Обе вкладки ходят в один сервис; здесь закреплено, как тип из формы
 * переводится в формат Poster (UI 1/2/3 → wire 1/0/2) и какие поля счёта и
 * суммы уходят для каждого типа. Ошибка в маппинге = приход, записанный
 * расходом, или деньги на не тот счёт — баланс в Poster поедет молча.
 *
 * Плюс защиты из аудита безопасности: только настроенные счета, лимит
 * суммы, формат даты, идемпотентность (двойной клик) и audit-строка.
 */
final class PosterTransactionCreateServiceTest extends TestCase
{
    /** @var list<array{method:string, params:array<string,mixed>, http:string}> */
    private array $calls = [];
    private InMemoryAuditLog $audit;

    private function make(PosterApiProviderInterface $provider): PosterTransactionCreateService
    {
        $this->audit = new InMemoryAuditLog();
        // Default settings: accounts 1 (Андрей), 8 (Tips), 9, 11, 2 are configured.
        return new PosterTransactionCreateService($provider, FixedSettings::defaults(), $this->audit, new PassThroughLock());
    }

    private function service(): PosterTransactionCreateService
    {
        $this->calls = [];
        $api = $this->createMock(PosterAPI::class);
        $api->method('request')->willReturnCallback(
            function (string $method, array $params = [], string $httpMethod = 'GET') {
                $this->calls[] = ['method' => $method, 'params' => $params, 'http' => $httpMethod];
                return ['transaction_id' => 777];
            }
        );
        $provider = $this->createMock(PosterApiProviderInterface::class);
        $provider->method('client')->willReturn($api);
        return $this->make($provider);
    }

    /** @return array<string,mixed> параметры единственного вызова Poster */
    private function sentPayload(): array
    {
        $this->assertCount(1, $this->calls, 'ровно один вызов Poster на одну транзакцию');
        $this->assertSame('finance.createTransactions', $this->calls[0]['method']);
        $this->assertSame('POST', $this->calls[0]['http']);
        return $this->calls[0]['params'];
    }

    public function test_income_goes_to_account_to_as_poster_type_1(): void
    {
        $res = $this->service()->create([
            'type' => 1, 'amount' => 350000, 'date' => '2026-09-18 14:05:00',
            'account_to' => 1, 'category_id' => 42, 'comment' => 'Created by op',
        ]);

        $p = $this->sentPayload();
        $this->assertSame(1, $p['type'], 'приход в Poster = type 1');
        $this->assertSame(1, $p['account_to']);
        $this->assertSame(350000, $p['amount_to']);
        $this->assertArrayNotHasKey('account_from', $p, 'у прихода нет счёта-источника');
        $this->assertArrayNotHasKey('amount_from', $p);
        $this->assertSame(42, $p['category']);
        $this->assertSame('2026-09-18 14:05:00', $p['date']);
        $this->assertSame('client', $p['timezone'], 'иначе Poster сдвинет время на свой часовой пояс');
        $this->assertTrue($res['ok']);
    }

    public function test_expense_goes_from_account_from_as_poster_type_0(): void
    {
        $this->service()->create([
            'type' => 2, 'amount' => 120000, 'date' => '2026-09-18 09:00:00',
            'account_from' => 1, 'category_id' => 7,
        ]);

        $p = $this->sentPayload();
        $this->assertSame(0, $p['type'], 'расход в Poster = type 0');
        $this->assertSame(1, $p['account_from']);
        $this->assertSame(120000, $p['amount_from']);
        $this->assertArrayNotHasKey('account_to', $p);
    }

    public function test_transfer_sends_both_sides_as_poster_type_2(): void
    {
        $this->service()->create([
            'type' => 3, 'amount' => 50000, 'date' => '2026-09-18 09:00:00',
            'account_from' => 1, 'account_to' => 8,
        ]);

        $p = $this->sentPayload();
        $this->assertSame(2, $p['type']);
        $this->assertSame([1, 8], [$p['account_from'], $p['account_to']]);
        $this->assertSame([50000, 50000], [$p['amount_from'], $p['amount_to']]);
        $this->assertArrayNotHasKey('category', $p, 'без категории поле не отправляем');
    }

    public function test_income_without_account_to_is_rejected_before_calling_poster(): void
    {
        $svc = $this->service();
        try {
            $svc->create(['type' => 1, 'amount' => 1000, 'date' => '2026-09-18 09:00:00', 'account_from' => 1]);
            $this->fail('приход без счёта-получателя должен отклоняться');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Account To', $e->getMessage());
        }
        $this->assertSame([], $this->calls, 'в Poster ничего не должно уйти');
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function invalidInputs(): array
    {
        return [
            'неизвестный тип'       => [['type' => 9, 'amount' => 1000, 'date' => '2026-09-18 10:00:00', 'account_to' => 1]],
            'нулевая сумма'         => [['type' => 1, 'amount' => 0,    'date' => '2026-09-18 10:00:00', 'account_to' => 1]],
            'без даты'              => [['type' => 1, 'amount' => 1000, 'date' => '',                    'account_to' => 1]],
            'перевод сам в себя'    => [['type' => 3, 'amount' => 1000, 'date' => '2026-09-18 10:00:00', 'account_from' => 1, 'account_to' => 1]],
            'сумма больше лимита'   => [['type' => 1, 'amount' => 1_000_000_001, 'date' => '2026-09-18 10:00:00', 'account_to' => 1]],
            'дата не в формате'     => [['type' => 1, 'amount' => 1000, 'date' => '18.09.2026',          'account_to' => 1]],
            'несуществующая дата'   => [['type' => 1, 'amount' => 1000, 'date' => '2026-02-30 10:00:00', 'account_to' => 1]],
            'счёт не из настроек'   => [['type' => 2, 'amount' => 1000, 'date' => '2026-09-18 10:00:00', 'account_from' => 555]],
            'перевод на чужой счёт' => [['type' => 3, 'amount' => 1000, 'date' => '2026-09-18 10:00:00', 'account_from' => 1, 'account_to' => 555]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected(array $input): void
    {
        $svc = $this->service();
        $this->expectException(\InvalidArgumentException::class);
        try {
            $svc->create($input);
        } finally {
            $this->assertSame([], $this->calls, 'в Poster ничего не должно уйти');
        }
    }

    public function test_poster_failure_surfaces_as_runtime_error(): void
    {
        $api = $this->createMock(PosterAPI::class);
        $api->method('request')->willThrowException(new \Exception('Poster down'));
        $provider = $this->createMock(PosterApiProviderInterface::class);
        $provider->method('client')->willReturn($api);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Poster: Poster down');
        $this->make($provider)->create([
            'type' => 1, 'amount' => 1000, 'date' => '2026-09-18 09:00:00', 'account_to' => 1,
        ]);
    }

    public function test_identical_request_from_same_session_within_window_is_rejected(): void
    {
        $svc   = $this->service();
        $actor = new Actor('op@example.com', 'sess-1');
        $input = ['type' => 2, 'amount' => 120000, 'date' => '2026-09-18 09:00:00', 'account_from' => 1, 'comment' => 'x'];

        $svc->create($input, $actor);
        try {
            $svc->create($input, $actor);
            $this->fail('двойной клик должен отклоняться');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('повтор', $e->getMessage());
        }
        $this->assertCount(1, $this->calls, 'в Poster ушла только одна транзакция');
    }

    public function test_same_request_from_another_session_or_with_other_amount_passes(): void
    {
        $svc   = $this->service();
        $input = ['type' => 2, 'amount' => 120000, 'date' => '2026-09-18 09:00:00', 'account_from' => 1];

        $svc->create($input, new Actor('a@example.com', 'sess-1'));
        $svc->create($input, new Actor('b@example.com', 'sess-2'));
        $svc->create(['amount' => 120001] + $input, new Actor('a@example.com', 'sess-1'));
        $this->assertCount(3, $this->calls);
    }

    public function test_created_transaction_is_audited_with_user_email(): void
    {
        $this->service()->create(
            ['type' => 1, 'amount' => 5000, 'date' => '2026-09-18 09:00', 'account_to' => 8],
            new Actor('op@example.com', 'sess-1'),
        );
        $this->assertCount(1, $this->audit->rows);
        $row = $this->audit->rows[0];
        $this->assertSame('op@example.com', $row['email']);
        $this->assertSame('poster_tx.create', $row['action']);
        $this->assertSame(8, $row['payload']['request']['account_to']);
        $this->assertNotNull($row['fingerprint']);
    }
}
