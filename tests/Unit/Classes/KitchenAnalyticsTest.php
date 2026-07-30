<?php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use App\Classes\KitchenAnalytics;
use App\Classes\PosterAPI;
use PHPUnit\Framework\TestCase;

/**
 * Разбор кухонной истории Poster (sendtokitchen / finishedcooking).
 *
 * Почему это критично: из этих событий берутся ticket_sent_at и
 * ready_pressed_at, а на них держится ВСЁ — «В очереди», «Долгих блюд»,
 * «В тайминге» и сами алерты о задержке. Когда Poster сменил форму
 * value_text, парсер молча перестал видеть отправки: ticket_sent_at стал
 * NULL у всех блюд, метрики обнулились, алерты не срабатывали неделями,
 * и НИЧЕГО не падало. Тесты ниже фиксируют оба формата, чтобы следующая
 * такая смена упала в CI, а не в проде.
 *
 * Парсер приватный — дёргаем через рефлексию: это характеризационный тест
 * легаси-кода, публичный getStatsForPeriod() лезет в сеть и здесь не нужен.
 */
final class KitchenAnalyticsTest extends TestCase
{
    private KitchenAnalytics $analytics;

    protected function setUp(): void
    {
        // Токен-заглушка: сетевые методы в этих тестах не вызываются.
        $this->analytics = new KitchenAnalytics(new PosterAPI('dummy-token'));
    }

    /** @return array<int, array<int, array{sent: ?string, ready: ?string, was_deleted: bool}>> */
    private function parse(array $history, array $productQty = []): array
    {
        $m = new \ReflectionMethod(KitchenAnalytics::class, 'extractProductKitchenInstances');
        $m->setAccessible(true);
        return $m->invoke($this->analytics, $history, $productQty);
    }

    private function spotTime(int $ms): string
    {
        $dt = new \DateTime('@' . (int) round($ms / 1000));
        $dt->setTimezone(new \DateTimeZone('Asia/Ho_Chi_Minh'));
        return $dt->format('Y-m-d H:i:s');
    }

    private function sendEvent(mixed $valueText, int $timeMs): array
    {
        return ['type_history' => 'sendtokitchen', 'time' => (string) $timeMs, 'value_text' => $valueText];
    }

    private function finishEvent(int $productId, int $timeMs): array
    {
        return ['type_history' => 'finishedcooking', 'time' => (string) $timeMs, 'value' => (string) $productId];
    }

    /**
     * АКТУАЛЬНЫЙ формат Poster: позиции завёрнуты в ключ `products`,
     * рядом лежит deviceId. Именно на нём парсер молча терял все отправки.
     */
    public function test_new_wrapped_payload_yields_sent_time(): void
    {
        $sentAt = 1785407907384;
        $history = [$this->sendEvent(
            ['products' => [['product_id' => 128, 'count' => 1, 'guestNumber' => 0]], 'deviceId' => '4024_sah5uh'],
            $sentAt
        )];

        $instances = $this->parse($history);

        $this->assertArrayHasKey(128, $instances, 'блюдо должно попасть в выборку');
        $this->assertCount(1, $instances[128]);
        $this->assertSame(
            $this->spotTime($sentAt),
            $instances[128][0]['sent'],
            'ticket_sent_at обязан извлекаться из нового формата — иначе метрики и алерты кухни мертвы'
        );
    }

    /** Исторический формат (плоский список) должен продолжать разбираться. */
    public function test_legacy_flat_payload_still_yields_sent_time(): void
    {
        $sentAt = 1785407907396;
        $history = [$this->sendEvent([['product_id' => 310, 'count' => 1]], $sentAt)];

        $instances = $this->parse($history);

        $this->assertSame($this->spotTime($sentAt), $instances[310][0]['sent']);
    }

    /** Иногда value_text приезжает JSON-строкой, а не массивом. */
    public function test_json_string_payload_is_decoded(): void
    {
        $sentAt = 1785400000000;
        $history = [$this->sendEvent(
            json_encode(['products' => [['product_id' => 55, 'count' => 1]]]),
            $sentAt
        )];

        $instances = $this->parse($history);

        $this->assertSame($this->spotTime($sentAt), $instances[55][0]['sent']);
    }

    /** count=3 в одном событии — три отдельных экземпляра блюда. */
    public function test_count_expands_into_separate_instances(): void
    {
        $history = [$this->sendEvent(
            ['products' => [['product_id' => 7, 'count' => 3]]],
            1785400000000
        )];

        $instances = $this->parse($history);

        $this->assertCount(3, $instances[7]);
    }

    /** Полный цикл: отправлено → готово. На этой паре считается «В тайминге». */
    public function test_sent_and_ready_are_paired(): void
    {
        $sentAt   = 1785400000000;
        $readyAt  = 1785400600000; // +10 минут
        $history  = [
            $this->sendEvent(['products' => [['product_id' => 42, 'count' => 1]]], $sentAt),
            $this->finishEvent(42, $readyAt),
        ];

        $instances = $this->parse($history);

        $this->assertSame($this->spotTime($sentAt), $instances[42][0]['sent']);
        $this->assertSame($this->spotTime($readyAt), $instances[42][0]['ready']);
    }

    /** Блюдо отправлено, но «готово» не нажали — ready остаётся пустым. */
    public function test_pending_dish_has_no_ready_time(): void
    {
        $history = [$this->sendEvent(['products' => [['product_id' => 9, 'count' => 1]]], 1785400000000)];

        $instances = $this->parse($history);

        $this->assertNull($instances[9][0]['ready']);
    }

    /** Мусор в value_text не должен ронять разбор всей транзакции. */
    public function test_malformed_payload_is_ignored_without_error(): void
    {
        $history = [
            $this->sendEvent('не json', 1785400000000),
            $this->sendEvent(['products' => 'тоже не список'], 1785400000000),
            $this->sendEvent(['products' => [['product_id' => 11, 'count' => 1]]], 1785400000000),
        ];

        $instances = $this->parse($history);

        $this->assertArrayHasKey(11, $instances, 'валидная позиция должна разобраться несмотря на соседний мусор');
    }
}
