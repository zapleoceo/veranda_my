<?php

declare(strict_types=1);

namespace App\OnlineOrder\Http\Actions;

use App\Infrastructure\Logger;
use App\OnlineOrder\Contracts\IncomingOrderServiceInterface;
use App\OnlineOrder\Contracts\OrderNotifierInterface;
use App\OnlineOrder\Contracts\PaymentQrProviderInterface;
use App\OnlineOrder\Contracts\TaxiDispatchInterface;
use App\OnlineOrder\Domain\CustomerInfo;
use App\OnlineOrder\Domain\DeliveryAddress;
use App\OnlineOrder\Domain\DeliveryQuote;
use App\OnlineOrder\Infrastructure\OnlineOrderConfig;
use App\OnlineOrder\Infrastructure\SubmitThrottle;
use App\OnlineOrder\Services\DeliveryQuoteService;
use App\Order\Contracts\PosterMenuProviderInterface;
use App\Order\Domain\CartLine;
use App\Order\Domain\MenuItem;
use App\Order\Http\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /onlineorder/api/orders — the checkout submit.
 *
 * Body: {
 *   customer: {name, phone, email?},
 *   address:  {address, point?:{lat,lng}, apartment?, note?},
 *   comment?: string,
 *   items:    [{product_id, count, modificator_id?, modifications?, comment?, label?}],
 *   website?: ''        ← honeypot, must stay empty
 * }
 *
 * Server is authoritative for everything money- or zone-related:
 * item ids are validated against the LIVE Poster menu (unknown or
 * hidden ids are rejected), the food total is computed from Poster
 * prices (client-sent prices are ignored), the delivery quote is
 * recalculated server-side, and the radius is re-checked. The client
 * only ever supplies intent.
 *
 * Flow: validate → price → quote → createIncomingOrder → payment QR
 * → Telegram alert (best-effort) → optional courier auto-dispatch
 * (best-effort). Nothing after the Poster write may fail the order.
 */
final class OrderCreateAction
{
    public function __construct(
        private readonly PosterMenuProviderInterface   $menu,
        private readonly DeliveryQuoteService          $quotes,
        private readonly IncomingOrderServiceInterface $orders,
        private readonly PaymentQrProviderInterface    $paymentQr,
        private readonly OrderNotifierInterface        $notifier,
        private readonly SubmitThrottle                $throttle,
        private readonly OnlineOrderConfig             $config,
        private readonly ?TaxiDispatchInterface        $dispatch,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $b = $this->readJson($request);
        if ($b === null) return JsonResponse::error($response, 'Bad JSON', 400);

        // Honeypot: a visible-to-bots-only field. Real browsers submit
        // it empty; auto-fillers don't. Reply with a neutral 400.
        if (trim((string)($b['website'] ?? '')) !== '') {
            return JsonResponse::error($response, 'rejected', 400);
        }

        if (!$this->throttle->allow()) {
            return JsonResponse::error($response, 'throttled', 429);
        }

        // ── 1. Parse + structural validation ─────────────────────
        $customer = CustomerInfo::fromInput(is_array($b['customer'] ?? null) ? $b['customer'] : []);
        if (!$customer->isValid()) {
            return JsonResponse::error($response, 'customer_invalid', 400);
        }
        $address = DeliveryAddress::fromInput(is_array($b['address'] ?? null) ? $b['address'] : []);
        if (!$address->isValid()) {
            return JsonResponse::error($response, 'address_invalid', 400);
        }

        $lines  = [];
        $labels = [];
        foreach (($b['items'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $line = CartLine::fromInput($row);
            if (!$line->isValid()) continue;
            $lines[]  = $line;
            $labels[] = trim((string)($row['label'] ?? ''));
        }
        if (!$lines) {
            return JsonResponse::error($response, 'cart_empty', 400);
        }

        // ── 2. Authoritative pricing against the live Poster menu ─
        try {
            $catalog = $this->catalog();
            $totalVnd = $this->priceCart($lines, $labels, $catalog);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, 'Poster: ' . $e->getMessage(), 502);
        }

        if ($this->config->minOrderVnd() > 0 && $totalVnd < $this->config->minOrderVnd()) {
            return JsonResponse::error($response, 'min_order', 400);
        }

        // ── 3. Server-side delivery quote (zone re-check included) ─
        $quoteResult = $this->quotes->quoteFor($address);
        $quote       = $quoteResult['quote'];
        if (!$quote->available && $quote->reason === 'out_of_zone') {
            return JsonResponse::error($response, 'out_of_zone', 400);
        }
        if ($quoteResult['point'] !== null && $address->point === null) {
            $address = new DeliveryAddress($address->text, $quoteResult['point'], $address->apartment, $address->note);
        }

        // ── 4. The one critical write: Poster incoming order ──────
        try {
            $created = $this->orders->create(
                customer:   $customer,
                address:    $address,
                lines:      $lines,
                lineLabels: $labels,
                comment:    trim((string)($b['comment'] ?? '')),
                quote:      $quote->available ? $quote : null,
            );
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return JsonResponse::error($response, $e->getMessage(), 502);
        }
        $orderId = $created['incoming_order_id'];
        $this->throttle->hit();

        // ── 5. Post-write extras — best-effort, never fail the order ─
        $reference = 'VERANDA ' . $orderId;
        $payment   = $this->paymentQr->qrFor($totalVnd, $reference);

        $dispatch = null;
        if ($this->dispatch !== null && $this->config->autoDispatch()
            && $quote->available && $address->point !== null) {
            try {
                $dispatch = $this->dispatch->dispatch(
                    $this->config->restaurant(),
                    $address->point,
                    $customer,
                    'VRD-' . $orderId,
                );
                unset($dispatch['raw']);
            } catch (\Throwable $e) {
                $dispatch = ['provider' => $quote->provider, 'error' => $e->getMessage()];
                $this->logWarn('auto-dispatch failed', $e);
            }
        }

        $this->notifier->notifyNewOrder([
            'order_id'  => $orderId,
            'name'      => $customer->name,
            'phone'     => $customer->phone,
            'address'   => $address->fullText(),
            'items'     => $this->itemSummaries($lines, $labels),
            'total_vnd' => $totalVnd,
            'delivery'  => $quote->toJson(),
            'payment'   => $payment,
            'dispatch'  => $dispatch,
        ]);

        return JsonResponse::ok($response, [
            'order_id'  => $orderId,
            'total_vnd' => $totalVnd,
            'quote'     => $quote->toJson(),
            'payment'   => $payment,
            'dispatch'  => $dispatch,
        ]);
    }

    // ─── Pricing helpers ──────────────────────────────────────────

    /** @return array<int, MenuItem> live menu keyed by product id */
    private function catalog(): array
    {
        $byId = [];
        foreach ($this->menu->fetchActiveProducts() as $p) {
            $byId[$p->id] = $p;
        }
        return $byId;
    }

    /**
     * Recompute the food total from Poster's own prices. A chosen
     * modifier option (dish variant) REPLACES the base price — that's
     * Poster's semantics; add-on modifications ADD price × count.
     * Backfills empty labels so the kitchen comment + Telegram alert
     * are readable even if the client sent none.
     *
     * @param CartLine[]          $lines
     * @param array<int,string>   $labels  by-ref backfill
     * @param array<int,MenuItem> $catalog
     */
    private function priceCart(array $lines, array &$labels, array $catalog): int
    {
        $total = 0;
        foreach ($lines as $i => $l) {
            $item = $catalog[$l->productId] ?? null;
            if ($item === null) {
                throw new \InvalidArgumentException('unknown_product:' . $l->productId);
            }

            $unit = $item->priceVnd;
            $name = $item->name;
            if ($l->modificatorId > 0) {
                $opt = $this->findOption($item, $l->modificatorId);
                if ($opt === null) {
                    throw new \InvalidArgumentException('unknown_modifier:' . $l->modificatorId);
                }
                $unit = (int)$opt['price'];
                $name .= ' (' . $opt['name'] . ')';
            }

            $addons = 0;
            foreach ($l->modifications as $m) {
                $addon = $this->findAddon($item, (int)$m['id']);
                if ($addon === null) {
                    throw new \InvalidArgumentException('unknown_addon:' . $m['id']);
                }
                $addons += (int)round(((int)$addon['price']) * (float)$m['count']);
                $name   .= ' +' . $addon['name'];
            }

            $total += (int)round(($unit + $addons) * $l->count);
            if (($labels[$i] ?? '') === '') {
                $labels[$i] = $name;
            }
        }
        return $total;
    }

    /** @return ?array{id:int,name:string,price:int} */
    private function findOption(MenuItem $item, int $optionId): ?array
    {
        foreach ($item->toJson()['modifier_groups'] as $g) {
            foreach ($g['options'] as $o) {
                if ((int)$o['id'] === $optionId) return $o;
            }
        }
        return null;
    }

    /** @return ?array{id:int,name:string,price:int} */
    private function findAddon(MenuItem $item, int $addonId): ?array
    {
        foreach ($item->toJson()['modifications'] as $m) {
            if ((int)$m['id'] === $addonId) return $m;
        }
        return null;
    }

    /** @param CartLine[] $lines */
    private function itemSummaries(array $lines, array $labels): array
    {
        $out = [];
        foreach ($lines as $i => $l) {
            $label = $labels[$i] !== '' ? $labels[$i] : 'Товар #' . $l->productId;
            $qty   = $l->count == (int)$l->count ? (string)(int)$l->count : (string)$l->count;
            $out[] = $label . ' × ' . $qty;
        }
        return $out;
    }

    private function readJson(ServerRequestInterface $request): ?array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed) return $parsed;
        $raw = (string)$request->getBody();
        if ($raw === '') return null;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : null;
    }

    private function logWarn(string $msg, \Throwable $e): void
    {
        try { Logger::get()->warning('[onlineorder/create] ' . $msg, ['err' => $e->getMessage()]); }
        catch (\Throwable $_) { error_log('[onlineorder/create] ' . $msg . ': ' . $e->getMessage()); }
    }
}
