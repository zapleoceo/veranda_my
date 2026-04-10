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
    public function request(string $method, array $params = [], string $httpMethod = 'GET') {
        $httpMethod = strtoupper($httpMethod);
        $params['token'] = $this->token;
        $url = $this->baseUrl . '/' . $method;
        $debugParams = $params;
        unset($debugParams['token']);

        if ($httpMethod === 'GET') {
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
        } else {
            $url .= '?' . http_build_query(['token' => $this->token]);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        if ($httpMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $postParams = $params;
            unset($postParams['token']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postParams));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("CURL Error: " . $error);
        }
        if (!is_string($response)) {
            throw new \Exception("Poster API Error: empty response (http=" . (int)$httpCode . ", method=" . $method . ")");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON Decode Error: " . json_last_error_msg());
        }

        if ($httpCode < 200 || $httpCode > 299) {
            $snippet = mb_substr($response, 0, 500);
            throw new \Exception("Poster API Error: http=" . (int)$httpCode . " method=" . $method . " params=" . json_encode($debugParams, JSON_UNESCAPED_UNICODE) . " body=" . $snippet);
        }

        if (isset($data['error'])) {
            $err = $data['error'];
            $msg = '';
            if (is_string($err)) {
                $msg = $err;
            } elseif (is_int($err) || is_float($err)) {
                $msg = (string)$err;
            } elseif (is_array($err)) {
                $msg = (string)($err['message'] ?? $err['msg'] ?? $err['error'] ?? '');
                if ($msg === '') {
                    $msg = json_encode($err, JSON_UNESCAPED_UNICODE);
                }
            } else {
                $msg = json_encode($err, JSON_UNESCAPED_UNICODE);
            }
            if (!is_string($msg) || $msg === '') $msg = 'Unknown error';
            throw new \Exception("Poster API Error: " . $msg . " (http=" . (int)$httpCode . ", method=" . $method . ", params=" . json_encode($debugParams, JSON_UNESCAPED_UNICODE) . ")");
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
