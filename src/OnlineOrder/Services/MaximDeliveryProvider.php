<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\OnlineOrder\Contracts\DeliveryQuoteProviderInterface;
use App\OnlineOrder\Contracts\TaxiDispatchInterface;
use App\OnlineOrder\Domain\CustomerInfo;
use App\OnlineOrder\Domain\DeliveryQuote;
use App\OnlineOrder\Domain\GeoPoint;
use App\OnlineOrder\Infrastructure\OnlineOrderConfig;

/**
 * Maxim (Taxsee) delivery — quote + courier dispatch.
 *
 * ВАЖНО: у Maxim НЕТ публичного self-service API. Доступ выдаётся по
 * партнёрскому договору (b2b@taximaxim.com / местный офис в Нячанге),
 * после чего партнёру приходят base-URL, ключ и точная спецификация.
 * Эндпоинты ниже соответствуют их типовому партнёрскому контракту
 * (calculate / create-order), но поле в поле сверьте со СВОЕЙ
 * спецификацией и поправьте mapPayload()/parseQuote() — всё
 * остальное (интерфейсы, чек-аут, fallback) уже готово и не меняется.
 *
 * Пока MAXIM_API_BASE / MAXIM_API_KEY пусты, провайдер честно
 * возвращает unavailable('not_configured') и страница работает через
 * fallback-режим.
 */
final class MaximDeliveryProvider implements DeliveryQuoteProviderInterface, TaxiDispatchInterface
{
    public function __construct(
        private readonly OnlineOrderConfig $config,
        private readonly HttpClient        $http,
    ) {}

    public function name(): string
    {
        return 'maxim';
    }

    public function quote(GeoPoint $from, GeoPoint $to): DeliveryQuote
    {
        if (!$this->config->isMaximConfigured()) {
            return DeliveryQuote::unavailable($this->name(), 'not_configured');
        }

        try {
            $resp = $this->call('/calculate', $this->mapPayload($from, $to));
            return $this->parseQuote($resp);
        } catch (\Throwable $e) {
            $this->logWarn('quote failed', $e);
            return DeliveryQuote::unavailable($this->name(), 'provider_error');
        }
    }

    public function dispatch(GeoPoint $pickup, GeoPoint $dropoff, CustomerInfo $recipient, string $orderRef): array
    {
        if (!$this->config->isMaximConfigured()) {
            throw new \RuntimeException('Maxim API не настроен (MAXIM_API_BASE / MAXIM_API_KEY)');
        }

        $payload = $this->mapPayload($pickup, $dropoff) + [
            'orderId'        => $orderRef,
            'clientName'     => $recipient->name,
            'clientPhone'    => $recipient->phone,
            'comment'        => 'Доставка еды Veranda, заказ ' . $orderRef,
        ];

        $resp = $this->call('/create-order', $payload);
        $id = (string)($resp['orderId'] ?? $resp['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Maxim: пустой ответ create-order');
        }

        return [
            'provider'    => $this->name(),
            'tracking_id' => $id,
            'status'      => (string)($resp['status'] ?? 'created'),
            'raw'         => $resp,
        ];
    }

    // ─── Wire plumbing (сверить с партнёрской спецификацией) ──────
    private function mapPayload(GeoPoint $from, GeoPoint $to): array
    {
        return [
            'cityId'    => $this->config->maximCityId(),
            'route'     => [
                ['lat' => $from->lat, 'lon' => $from->lng, 'address' => $this->config->restaurantAddress()],
                ['lat' => $to->lat,   'lon' => $to->lng,   'address' => (string)($to->address ?? '')],
            ],
            'tariff'    => 'delivery',
        ];
    }

    private function parseQuote(array $resp): DeliveryQuote
    {
        $price = $resp['price'] ?? $resp['sum'] ?? null;
        if (!is_numeric($price)) {
            throw new \RuntimeException('Maxim: нет цены в ответе calculate');
        }
        $distanceKm = isset($resp['distance']) && is_numeric($resp['distance'])
            ? (float)$resp['distance']
            : null;
        $eta = isset($resp['eta']) && is_numeric($resp['eta']) ? (int)$resp['eta'] : null;

        return DeliveryQuote::ok($this->name(), (int)round((float)$price), $distanceKm, $eta);
    }

    private function call(string $path, array $payload): array
    {
        $resp = $this->http->postJsonBodyWithHeaders(
            rtrim($this->config->maximApiBase(), '/') . $path,
            $payload,
            ['Authorization: Bearer ' . $this->config->maximApiKey()],
        );
        if (!is_array($resp)) {
            throw new \RuntimeException('Maxim: empty/non-JSON response for ' . $path);
        }
        return $resp;
    }

    private function logWarn(string $msg, \Throwable $e): void
    {
        try { Logger::get()->warning('[onlineorder/maxim] ' . $msg, ['err' => $e->getMessage()]); }
        catch (\Throwable $_) { error_log('[onlineorder/maxim] ' . $msg . ': ' . $e->getMessage()); }
    }
}
