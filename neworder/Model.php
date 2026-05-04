<?php

require_once __DIR__ . '/../src/classes/PosterAPI.php';

class NewOrderModel
{
    private ?\App\Classes\PosterAPI $posterApi;
    private int $spotId;

    public function __construct(?\App\Classes\PosterAPI $posterApi, int $spotId)
    {
        $this->posterApi = $posterApi;
        $this->spotId = $spotId > 0 ? $spotId : 1;
    }

    public function searchProducts(string $query, int $limit = 30): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }

        $all = $this->getPosterProductsDirect();
        $out = [];

        foreach ($all as $p) {
            if (!is_array($p)) {
                continue;
            }

            if ((string)($p['hidden'] ?? '0') === '1') {
                continue;
            }

            $name = trim((string)($p['product_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (mb_stripos($name, $q) === false) {
                continue;
            }

            $price = $this->extractProductPrice($p);
            $out[] = [
                'id' => (int)($p['product_id'] ?? 0),
                'name' => $name,
                'desc' => trim((string)($p['category_name'] ?? '')),
                'price' => $price,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function getPosterProductsDirect(): array
    {
        if (!$this->posterApi) {
            throw new \RuntimeException('Poster API Token not set');
        }

        $resp = $this->posterApi->request('menu.getProducts', ['type' => 'products'], 'GET');
        return is_array($resp) ? $resp : [];
    }

    public function createIncomingOrder(string $phoneE164, string $name, int $serviceMode, array $products): array
    {
        if (!$this->posterApi) {
            throw new \RuntimeException('Poster API Token not set');
        }

        $orderData = [
            'spot_id' => $this->spotId,
            'phone' => $phoneE164,
            'first_name' => $name,
            'service_mode' => $serviceMode,
            'products' => $products,
        ];

        $resp = $this->posterApi->request('incomingOrders.createIncomingOrder', $orderData, 'POST', true);
        $orderId = (int)($resp['incoming_order_id'] ?? $resp['id'] ?? 0);
        if ($orderId <= 0) {
            return ['order_id' => 0, 'raw' => $resp];
        }

        return ['order_id' => $orderId];
    }

    private function extractProductPrice(array $p): ?int
    {
        $spots = $p['spots'] ?? null;
        if (is_array($spots)) {
            foreach ($spots as $s) {
                if (!is_array($s)) {
                    continue;
                }
                if ((int)($s['spot_id'] ?? 0) !== $this->spotId) {
                    continue;
                }
                if ((string)($s['visible'] ?? '1') === '0') {
                    continue;
                }
                $v = $s['price'] ?? null;
                if (is_numeric($v)) {
                    return (int)$v;
                }
                if (is_string($v) && preg_match('/^\d+$/', $v)) {
                    return (int)$v;
                }
            }
        }

        $price = $p['price'] ?? null;
        if (is_array($price)) {
            $key = (string)$this->spotId;
            if (isset($price[$key]) && is_numeric($price[$key])) {
                return (int)$price[$key];
            }
            foreach ($price as $v) {
                if (is_numeric($v)) {
                    return (int)$v;
                }
                if (is_string($v) && preg_match('/^\d+$/', $v)) {
                    return (int)$v;
                }
            }
        }

        return null;
    }
}
