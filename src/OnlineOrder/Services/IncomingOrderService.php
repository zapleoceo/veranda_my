<?php

declare(strict_types=1);

namespace App\OnlineOrder\Services;

use App\Infrastructure\Logger;
use App\OnlineOrder\Contracts\IncomingOrderServiceInterface;
use App\OnlineOrder\Domain\CustomerInfo;
use App\OnlineOrder\Domain\DeliveryAddress;
use App\OnlineOrder\Domain\DeliveryQuote;
use App\OnlineOrder\Infrastructure\OnlineOrderConfig;
use App\Order\Domain\CartLine;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Poster write-side for /onlineorder.
 *
 * Uses incomingOrders.createIncomingOrder — Poster's purpose-built
 * online-order endpoint (form-encoded POST /api/<method>, same client
 * the rest of the codebase uses). Unlike the dine-in POST /orders the
 * /neworder module uses, an incoming order natively carries the
 * client's phone / name / delivery address and lands on the POS
 * register as a pending online order the staff explicitly accepts.
 *
 * service_mode=3 → delivery. Money fields (delivery_price) go in
 * Poster minor units: VND × 100 (mirror of Money::fromPosterCents).
 */
final class IncomingOrderService implements IncomingOrderServiceInterface
{
    public function __construct(
        private readonly PosterApiProviderInterface $poster,
        private readonly OnlineOrderConfig          $config,
    ) {}

    public function create(
        CustomerInfo    $customer,
        DeliveryAddress $address,
        array           $lines,
        array           $lineLabels,
        string          $comment,
        ?DeliveryQuote  $quote,
    ): array {
        $lines = array_values(array_filter($lines, fn(CartLine $l) => $l->isValid()));
        if (!$lines) {
            throw new \InvalidArgumentException('Корзина пуста');
        }
        if (!$customer->isValid()) {
            throw new \InvalidArgumentException('Имя и телефон обязательны');
        }
        if (!$address->isValid()) {
            throw new \InvalidArgumentException('Адрес доставки обязателен');
        }

        $params = [
            'spot_id'      => $this->config->spotId(),
            // Poster rejects E.164 "+84..." with error 155 «invalid
            // field: Client phone» (verified live) — it wants bare
            // digits and stores "84...". The "+" stays everywhere else
            // (Telegram alert, Grab dispatch need E.164).
            'phone'        => ltrim($customer->phone, '+'),
            'first_name'   => self::posterSafe($customer->firstName()),
            'service_mode' => 3, // delivery
            'address'      => self::posterSafe($address->fullText()),
            'products'     => array_map(fn(CartLine $l) => $this->lineToProduct($l), $lines),
            'comment'      => self::posterSafe($this->buildComment($comment, $lines, $lineLabels, $quote)),
        ];
        if ($customer->lastName() !== '') $params['last_name'] = self::posterSafe($customer->lastName());
        if ($customer->email !== null)    $params['email']     = $customer->email;

        // Structured address block — Poster's delivery UI (and the
        // courier app integrations) read lat/lng from client_address.
        // Verified live: when client_address is present Poster shows
        // ITS address1 as the order address (the flat `address` param
        // is overridden) — so address1 must carry the FULL line
        // (apartment + street + landmark), not the bare street.
        $clientAddress = ['address1' => self::posterSafe($address->fullText())];
        if ($address->apartment !== '') $clientAddress['address2'] = self::posterSafe($address->apartment);
        if ($address->note !== '')      $clientAddress['comment']  = self::posterSafe($address->note);
        if ($address->point !== null) {
            $clientAddress['lat'] = (string)$address->point->lat;
            $clientAddress['lng'] = (string)$address->point->lng;
        }
        $params['client_address'] = $clientAddress;

        if ($quote !== null && $quote->available && $quote->feeVnd > 0) {
            $params['delivery_price'] = $quote->feeVnd * 100; // VND → Poster minor units
        }

        try {
            $resp = $this->poster->client()->request('incomingOrders.createIncomingOrder', $params, 'POST');
        } catch (\Throwable $e) {
            $this->logErr('createIncomingOrder failed', ['err' => $e->getMessage(), 'items' => count($lines)]);
            throw new \RuntimeException('Poster API: ' . $e->getMessage(), 0, $e);
        }

        // PosterApiClient already unwraps ['response'] — be tolerant to
        // both shapes anyway.
        $orderId = 0;
        if (is_array($resp)) {
            $orderId = (int)($resp['incoming_order_id'] ?? $resp['response']['incoming_order_id'] ?? 0);
        } elseif (is_numeric($resp)) {
            $orderId = (int)$resp;
        }
        if ($orderId <= 0) {
            $this->logErr('createIncomingOrder: no id', ['resp' => json_encode($resp, JSON_UNESCAPED_UNICODE)]);
            throw new \RuntimeException('Poster API: заказ не создан (пустой ответ)');
        }

        return ['incoming_order_id' => $orderId];
    }

    /**
     * Wire shape per createIncomingOrder docs: products[n][product_id],
     * [count], optional [modificator_id]. Add-on group modifications go
     * as the same {"m","a"} JSON used by transactions.addTransactionProduct;
     * the kitchen-readable backup lives in the comment (buildComment).
     */
    private function lineToProduct(CartLine $l): array
    {
        $row = ['product_id' => $l->productId, 'count' => $l->count];
        if ($l->modificatorId > 0) {
            $row['modificator_id'] = $l->modificatorId;
        }
        if ($l->modifications) {
            $row['modification'] = json_encode(
                array_map(static fn(array $m) => ['m' => $m['id'], 'a' => $m['count']], $l->modifications),
                JSON_UNESCAPED_UNICODE,
            ) ?: '';
        }
        return $row;
    }

    /**
     * Order-level comment the kitchen actually reads: payment status,
     * courier info, then per-line notes/extras (labels come from the
     * client purely for human readability — ids are authoritative).
     */
    /**
     * Poster's storage is utf8mb3 — any 4-byte UTF-8 character (emoji
     * etc.) NULLS the whole field on their side (verified live: a
     * comment starting with 🛵 came back null, the same text without
     * it survived intact). Strip supplementary-plane chars from every
     * string we send; the customer's "🌶️ поострее" arrives as
     * "поострее" instead of vanishing.
     */
    private static function posterSafe(string $s): string
    {
        return trim((string)preg_replace('/[\x{10000}-\x{10FFFF}\x{FE0F}\x{200D}]/u', '', $s));
    }

    private function buildComment(string $comment, array $lines, array $lineLabels, ?DeliveryQuote $quote): string
    {
        $parts = ['ОНЛАЙН-ДОСТАВКА (veranda.my/onlineorder)'];
        $parts[] = 'Оплата еды: QR-перевод — проверить поступление!';

        if ($quote !== null && $quote->available) {
            $parts[] = sprintf(
                'Доставка: %s ₫ (%s%s) — оплачивается курьеру',
                number_format($quote->feeVnd, 0, '.', ' '),
                $quote->provider,
                $quote->distanceKm !== null ? ', ~' . $quote->distanceKm . ' км' : '',
            );
        } else {
            $parts[] = 'Доставка: стоимость уточнить и согласовать с клиентом';
        }

        $comment = trim($comment);
        if ($comment !== '') {
            $parts[] = 'Клиент: ' . $comment;
        }

        foreach ($lines as $i => $l) {
            if ($l->comment === '') continue;
            $label = $lineLabels[$i] ?? ('Товар #' . $l->productId);
            $parts[] = $label . ': ' . $l->comment;
        }

        return implode("\n", $parts);
    }

    private function logErr(string $msg, array $ctx): void
    {
        try { Logger::get()->error('[onlineorder/incoming] ' . $msg, $ctx); }
        catch (\Throwable $_) { error_log('[onlineorder/incoming] ' . $msg . ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE)); }
    }
}
