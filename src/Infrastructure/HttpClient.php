<?php

declare(strict_types=1);

namespace App\Infrastructure;

class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 10
    ) {}

    /** POST with form-encoded body, returns decoded JSON or null on failure */
    public function postJson(string $url, array $params = []): array|null
    {
        $ch = $this->_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        return $this->_exec($ch);
    }

    /** POST with JSON body */
    public function postJsonBody(string $url, array $data = []): array|null
    {
        $ch = $this->_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        return $this->_exec($ch);
    }

    /** GET request, returns decoded JSON or null on failure */
    public function getJson(string $url, array $params = []): array|null
    {
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        $ch = $this->_init($url);
        return $this->_exec($ch);
    }

    /** POST with JSON body and custom headers (e.g. auth headers for WA bridge) */
    public function postJsonBodyWithHeaders(string $url, array $data, array $headers): array|null
    {
        $ch = $this->_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
            ['Content-Type: application/json'],
            $headers
        ));
        return $this->_exec($ch);
    }

    /** GET with custom headers (e.g. User-Agent for Nominatim, Bearer auth) */
    public function getJsonWithHeaders(string $url, array $params, array $headers): array|null
    {
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        $ch = $this->_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        return $this->_exec($ch);
    }

    /** POST multipart/form-data (for file uploads) */
    public function postMultipart(string $url, array $fields): array|null
    {
        $ch = $this->_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        return $this->_exec($ch);
    }

    private function _init(string $url): \CurlHandle
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        return $ch;
    }

    private function _exec(\CurlHandle $ch): array|null
    {
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            Logger::get()->warning('HttpClient curl error', ['error' => $error]);
            return null;
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            Logger::get()->warning('HttpClient non-JSON response', [
                'url'  => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
                'body' => substr((string) $response, 0, 200),
            ]);
            return null;
        }

        return $decoded;
    }
}
