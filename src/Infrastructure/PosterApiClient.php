<?php

declare(strict_types=1);

namespace App\Infrastructure;

/**
 * Poster POS API client (v2 + v3).
 * Migrated from App\Classes\PosterAPI — uses HttpClient, proper PHP 8.2 types.
 */
class PosterApiClient
{
    private const BASE_URL_V2 = 'https://joinposter.com/api';
    private const BASE_URL_V3 = 'https://api.joinposter.com/v3';

    public function __construct(
        private readonly string     $token,
        private readonly HttpClient $http,
    ) {}

    /**
     * @throws \RuntimeException on HTTP or API error
     * @return array|mixed
     */
    public function request(string $method, array $params = [], string $httpMethod = 'GET'): mixed
    {
        $httpMethod = strtoupper($httpMethod);
        $url        = self::BASE_URL_V2 . '/' . $method;
        $params['token'] = $this->token;

        if ($httpMethod === 'GET') {
            $result = $this->http->getJson($url, $params);
        } else {
            $result = $this->http->postJson($url . '?' . http_build_query(['token' => $this->token]),
                array_filter($params, fn($k) => $k !== 'token', ARRAY_FILTER_USE_KEY));
        }

        if ($result === null) {
            throw new \RuntimeException("Poster API request failed: {$method}");
        }

        if (isset($result['error'])) {
            $msg = is_string($result['error']) ? $result['error'] : json_encode($result['error']);
            throw new \RuntimeException("Poster API error: {$msg} (method={$method})");
        }

        return $result['response'] ?? $result;
    }

    public function requestV3(string $method, array $params = []): mixed
    {
        $url    = self::BASE_URL_V3 . '/' . $method;
        $result = $this->http->postJsonBodyWithHeaders(
            $url,
            $params,
            ['Authorization: Bearer ' . $this->token]
        );

        if ($result === null) {
            throw new \RuntimeException("Poster API v3 request failed: {$method}");
        }

        return $result['response'] ?? $result;
    }

    public function getTransactions(string $dateFrom, string $dateTo): array
    {
        return (array) $this->request('dash.getTransactions', [
            'dateFrom'        => str_replace('-', '', $dateFrom),
            'dateTo'          => str_replace('-', '', $dateTo),
            'include_products'=> 'true',
            'include_history' => 'true',
            'status'          => 0,
        ]);
    }

    public function getTransaction(int $transactionId): array
    {
        $result = $this->request('dash.getTransaction', [
            'transaction_id'  => $transactionId,
            'include_history' => 1,
            'include_products'=> 1,
        ]);
        return is_array($result) ? $result : [$result];
    }
}
