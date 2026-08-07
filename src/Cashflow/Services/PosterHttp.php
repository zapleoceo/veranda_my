<?php

declare(strict_types=1);

namespace App\Cashflow\Services;

/**
 * Thin Poster HTTP GET helper — single request + parallel (curl_multi).
 * Shared by the revenue (per-day fan-out) and expense (one month call) services.
 */
final class PosterHttp
{
    private const API = 'https://joinposter.com/api/';

    public function __construct(
        private readonly string $token,
        private readonly string $caInfo = ''
    ) {}

    /** Single GET → Poster `response` payload. Throws on transport/API error. */
    public function get(string $method, array $params): array
    {
        $params['token'] = $this->token;
        $ch = curl_init(self::API . $method . '?' . http_build_query($params));
        $this->applyOpts($ch);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Poster {$method}: {$err}");
        }
        curl_close($ch);
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Poster {$method}: bad JSON");
        }
        if (!empty($data['error'])) {
            throw new \RuntimeException("Poster {$method}: error " . json_encode($data['error'], JSON_UNESCAPED_UNICODE));
        }
        return $data['response'] ?? [];
    }

    /**
     * Parallel GETs via curl_multi. Returns responses indexed like $paramSets.
     * A failed handle yields [] for that index rather than failing the batch.
     *
     * @param  list<array<string,string>> $paramSets
     * @return array<int,array>
     */
    public function getMany(string $method, array $paramSets): array
    {
        if ($paramSets === []) {
            return [];
        }
        $mh      = curl_multi_init();
        $handles = [];
        foreach ($paramSets as $i => $params) {
            $params['token'] = $this->token;
            $ch = curl_init(self::API . $method . '?' . http_build_query($params));
            $this->applyOpts($ch);
            $handles[$i] = $ch;
            curl_multi_add_handle($mh, $ch);
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $i => $ch) {
            $body    = curl_multi_getcontent($ch);
            $data    = is_string($body) ? json_decode($body, true) : null;
            $out[$i] = (is_array($data) && isset($data['response']) && is_array($data['response']))
                ? $data['response']
                : [];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    private function applyOpts(\CurlHandle $ch): void
    {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($this->caInfo !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $this->caInfo);
        }
    }
}
