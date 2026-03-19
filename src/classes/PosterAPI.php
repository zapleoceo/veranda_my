<?php

namespace App\Classes;

class PosterAPI {
    private string $token;
    private string $baseUrl;

    public function __construct(string $token, string $baseUrl = 'https://joinposter.com/api') {
        $this->token = $token;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Выполнение запроса к Poster API
     */
    public function request(string $method, array $params = [], string $httpMethod = 'GET'): array {
        $params['token'] = $this->token;
        $url = $this->baseUrl . '/' . $method;

        if ($httpMethod === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($httpMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("CURL Error: " . $error);
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON Decode Error: " . json_last_error_msg());
        }

        if (isset($data['error'])) {
            throw new \Exception("Poster API Error: " . ($data['error']['message'] ?? 'Unknown error'));
        }

        return $data['response'] ?? $data;
    }

    /**
     * Получение списка транзакций за период
     */
    public function getTransactions(string $dateFrom, string $dateTo): array {
        return $this->request('dash.getTransactions', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo' => str_replace('-', '', $dateTo),
            'include_products' => 'true',
            'include_history' => 'true',
            'status' => 0 // Все статусы (открытые, закрытые, удаленные)
        ]);
    }

    /**
     * Получение детальной информации о транзакции с историей
     */
    public function getTransaction(int $transactionId): array {
        return $this->request('dash.getTransaction', [
            'transaction_id' => $transactionId,
            'include_history' => 1,
            'include_products' => 1
        ]);
    }
}
