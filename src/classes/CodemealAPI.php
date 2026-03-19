<?php

namespace App\Classes;

class CodemealAPI {
    private string $baseUrl;
    private string $auth;
    private string $clientNumber;
    private string $locale;
    private string $timezone;
    private int $timeout;

    public function __construct(
        string $baseUrl,
        string $auth,
        string $clientNumber,
        string $locale = 'en',
        string $timezone = 'Asia/Ho_Chi_Minh',
        int $timeout = 15
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->auth = $auth;
        $this->clientNumber = $clientNumber;
        $this->locale = $locale;
        $this->timezone = $timezone;
        $this->timeout = $timeout;
    }

    public function getOrders(string $from, ?string $to = null, string $state = '', string $term = '', int $page = 1): array {
        $qs = [
            'userId' => '',
            'from' => $from,
            'to' => $to ?? '',
            'state' => $state,
            'term' => $term,
            'currentPage' => $page,
        ];
        $url = $this->baseUrl . '/Home/_Orders?' . http_build_query($qs);
        return $this->getJson($url, 'application/json, text/javascript, */*; q=0.01');
    }

    public function getOrderTableSettings(): array {
        $url = $this->baseUrl . '/Home/GetOrderTableSettings';
        return $this->getJson($url, '*/*');
    }

    private function getJson(string $url, string $accept): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: ' . $accept,
            'Authorization: ' . $this->auth,
            'Client-Number: ' . $this->clientNumber,
            'Locale: ' . $this->locale,
            'Timezone: ' . $this->timezone,
            'X-Requested-With: XMLHttpRequest',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            throw new \Exception('CURL error: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new \Exception('HTTP error: ' . $code);
        }
        $data = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON decode error: ' . json_last_error_msg());
        }
        return is_array($data) ? $data : [];
    }
}
