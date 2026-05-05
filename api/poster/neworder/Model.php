<?php

require_once __DIR__ . '/../../../src/classes/PosterAPI.php';

class ApiPosterNewOrderModel
{
    private ?\App\Classes\PosterAPI $posterApi;
    private int $spotId;
    private string $apiToken;

    public function __construct(?\App\Classes\PosterAPI $posterApi, int $spotId, string $apiToken)
    {
        $this->posterApi = $posterApi;
        $this->spotId = $spotId > 0 ? $spotId : 1;
        $this->apiToken = trim($apiToken);
    }

    public function getPosterProductsDirect(): array
    {
        if (!$this->posterApi) {
            throw new \RuntimeException('Poster API Token not set');
        }

        $resp = $this->posterApi->request('menu.getProducts', ['hidden' => 0], 'GET');
        return is_array($resp) ? $resp : [];
    }

    public function getTables(int $spotId, int $hallId = 0): array
    {
        if (!$this->posterApi) {
            throw new \RuntimeException('Poster API Token not set');
        }

        $spotId = $spotId > 0 ? $spotId : $this->spotId;
        $params = [
            'spot_id' => $spotId,
            'without_deleted' => 1,
        ];
        if ($hallId > 0) {
            $params['hall_id'] = $hallId;
        }

        $resp = $this->posterApi->request('spots.getTableHallTables', $params, 'GET');
        return is_array($resp) ? $resp : [];
    }

    public function createOrder(int $spotId, int $tableId, int $waiterId, int $serviceMode, string $phoneE164, string $name, array $products): array
    {
        $spotId = $spotId > 0 ? $spotId : $this->spotId;
        if ($this->apiToken === '') {
            throw new \RuntimeException('Poster API Token not set');
        }

        $orderProducts = [];
        foreach ($products as $p) {
            $pid = (int)($p['product_id'] ?? $p['id'] ?? 0);
            $cnt = $p['count'] ?? 1;
            if ($pid <= 0) continue;
            if (!is_numeric($cnt)) $cnt = 1;
            $orderProducts[] = ['id' => $pid, 'count' => (float)$cnt];
        }
        if (!$orderProducts) {
            return ['order_id' => 0];
        }

        $payload = [
            'spotId' => $spotId,
            'tableId' => $tableId > 0 ? $tableId : 0,
            'waiterId' => $waiterId > 0 ? $waiterId : 0,
            'guestsCount' => 1,
            'serviceMode' => $serviceMode,
            'products' => $orderProducts,
        ];

        $phone = trim($phoneE164);
        $name = trim($name);
        if ($phone !== '' || $name !== '') {
            $payload['client'] = [];
            if ($phone !== '') $payload['client']['phone'] = $phone;
            if ($name !== '') $payload['client']['firstName'] = $name;
        }

        $url = 'https://joinposter.com/api/orders?token=' . rawurlencode($this->apiToken);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException('CURL Error: ' . $err);
        }
        if (!is_string($resp) || $resp === '') {
            throw new \RuntimeException('Poster API Error: empty response (http=' . $httpCode . ')');
        }
        $j = json_decode($resp, true);
        if (!is_array($j)) {
            throw new \RuntimeException('Poster API Error: invalid JSON (http=' . $httpCode . ')');
        }
        if ($httpCode < 200 || $httpCode > 299) {
            throw new \RuntimeException('Poster API Error: http=' . $httpCode);
        }

        $orderId = (int)($j['response']['id'] ?? 0);
        return ['order_id' => $orderId];
    }
}
