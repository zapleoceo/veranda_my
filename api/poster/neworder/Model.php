<?php

require_once __DIR__ . '/../../../src/classes/PosterAPI.php';

class ApiPosterNewOrderModel
{
    private ?\App\Classes\PosterAPI $posterApi;
    private int $spotId;

    public function __construct(?\App\Classes\PosterAPI $posterApi, int $spotId)
    {
        $this->posterApi = $posterApi;
        $this->spotId = $spotId > 0 ? $spotId : 1;
    }

    public function getPosterProductsDirect(): array
    {
        if (!$this->posterApi) {
            throw new \RuntimeException('Poster API Token not set');
        }

        $resp = $this->posterApi->request('menu.getProducts', [], 'GET');
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
            return ['order_id' => 0];
        }

        return ['order_id' => $orderId];
    }
}
