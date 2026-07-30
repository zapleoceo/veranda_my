<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3;

use App\Classes\PosterAPI;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\LocalSettings;
use App\Payday3\Services\FinancePosterService;
use PHPUnit\Framework\TestCase;

/**
 * Выборка финансовых транзакций из Poster (счёт Андрея + Tips).
 *
 * Регрессия: в настройках лежат ИДЕНТИФИКАТОРЫ счетов (1 = «Счет Андрея»,
 * 8 = «Tips»), а код подставлял их в параметр account_type, который принимает
 * только 1|2|3 (банк / карта / наличные).
 *
 * Для счёта Андрея это случайно «работало»: account_type=1 возвращает ВСЕ
 * банковские счета разом, включая Tips. Второй вызов с account_type=8 всегда
 * падал с ошибкой 216 и молча глотался. То есть отчёт сходился по совпадению —
 * стоило сменить счёт Андрея на счёт другого типа, и чаевые исчезли бы без
 * следа и без ошибки.
 *
 * Проверено на проде (июль 2026): account_type=1 → 312 строк (счета 1 и 8),
 * account_id=1 → 266, account_id=8 → 46. Итог тот же, но выборка перестаёт
 * зависеть от типа счёта.
 */
final class FinancePosterServiceTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $capturedParams = [];

    private function makeService(int $andreyId = 1, int $tipsId = 8): FinancePosterService
    {
        $this->capturedParams = [];

        $api = $this->createMock(PosterAPI::class);
        $api->method('request')->willReturnCallback(
            function (string $method, array $params = [], string $httpMethod = 'GET') {
                $this->capturedParams[] = $params;
                return [];
            }
        );

        $provider = $this->createMock(PosterApiProviderInterface::class);
        $provider->method('client')->willReturn($api);

        $settings = $this->createMock(LocalSettingsRepositoryInterface::class);
        $settings->method('load')->willReturn(new LocalSettings(
            telegramChatId:       '-100',
            telegramThreadId:     '1',
            serviceUserId:        1,
            accountAndreyId:      $andreyId,
            accountTipsId:        $tipsId,
            accountVietnamId:     9,
            balanceSyncAccountId: 1,
            allowedCategories:    [],
            customCategoryNames:  [],
            posterAdmin:          [],
        ));

        return new FinancePosterService($provider, $settings);
    }

    public function test_queries_by_account_id_not_account_type(): void
    {
        $this->makeService()->fetch(DateRange::of('2026-07-01', '2026-07-30'));

        $this->assertCount(2, $this->capturedParams, 'должно быть по запросу на каждый счёт');
        foreach ($this->capturedParams as $params) {
            $this->assertArrayHasKey('account_id', $params);
            $this->assertArrayNotHasKey(
                'account_type',
                $params,
                'account_type принимает только 1|2|3 — id счёта туда класть нельзя (ошибка 216)'
            );
        }
    }

    public function test_queries_both_configured_accounts(): void
    {
        $this->makeService(andreyId: 1, tipsId: 8)->fetch(DateRange::of('2026-07-01', '2026-07-30'));

        $ids = array_map(static fn(array $p): int => (int) $p['account_id'], $this->capturedParams);
        sort($ids);

        $this->assertSame([1, 8], $ids, 'счёт чаевых обязан запрашиваться отдельно, а не «прилипать» по типу');
    }

    /** Незаполненный счёт в настройках не должен превращаться в запрос account_id=0. */
    public function test_skips_unconfigured_account(): void
    {
        $this->makeService(andreyId: 1, tipsId: 0)->fetch(DateRange::of('2026-07-01', '2026-07-30'));

        $this->assertCount(1, $this->capturedParams);
        $this->assertSame(1, (int) $this->capturedParams[0]['account_id']);
    }

    /** Даты уходят в формате Ymd, как ждёт Poster, и с таймзоной спота. */
    public function test_sends_poster_date_format_and_client_timezone(): void
    {
        $this->makeService()->fetch(DateRange::of('2026-07-01', '2026-07-30'));

        $this->assertSame('20260701', $this->capturedParams[0]['dateFrom']);
        $this->assertSame('20260730', $this->capturedParams[0]['dateTo']);
        $this->assertSame('client',   $this->capturedParams[0]['timezone']);
    }
}
