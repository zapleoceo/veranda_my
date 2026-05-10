<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Infra;

class GeminiClient {
    private string $apiKey;
    private string $proxyBase;
    private string $proxyKey;
    private ?Logger $log;

    public function __construct(string $apiKey, string $proxyBase, string $proxyKey, ?Logger $log = null) {
        $this->apiKey    = trim($apiKey);
        $this->proxyBase = rtrim(trim($proxyBase), '/');
        $this->proxyKey  = trim($proxyKey);
        $this->log       = $log;
    }

    public function canCall(): bool {
        return $this->proxyBase !== '' || $this->apiKey !== '';
    }

    public function generate(string $model, array $parts, array $opts = []): array {
        if ($this->proxyBase !== '') {
            $endpoint = $this->proxyBase . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
        } else {
            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);
        }

        $payload = ['contents' => [['role' => 'user', 'parts' => $parts]]];

        if (!empty($opts['system'])) {
            $payload['systemInstruction'] = ['parts' => [['text' => (string)$opts['system']]]];
        }

        $gen = [];
        if (array_key_exists('temperature', $opts))    $gen['temperature']      = (float)$opts['temperature'];
        if (array_key_exists('maxOutputTokens', $opts)) $gen['maxOutputTokens'] = (int)$opts['maxOutputTokens'];
        if (!empty($opts['responseMimeType']))          $gen['responseMimeType'] = (string)$opts['responseMimeType'];
        if ($gen) $payload['generationConfig'] = $gen;

        $headers = ['Content-Type: application/json'];
        if ($this->proxyBase !== '' && $this->proxyKey !== '') {
            $headers[] = 'X-Veranda-Key: '      . $this->proxyKey;
            $headers[] = 'X-Gemini-Proxy-Key: ' . $this->proxyKey;
            $headers[] = 'X-Proxy-Key: '        . $this->proxyKey;
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode(is_string($resp) ? $resp : '', true);
        if (!is_array($data)) $data = [];
        $data['_http_code'] = $code;

        if ($this->log) {
            $err = '';
            if (is_array($data['error'] ?? null)) $err = mb_substr(trim((string)($data['error']['message'] ?? '')), 0, 500);
            $this->log->info('gemini_http', [
                'http_code'  => $code,
                'model'      => $model,
                'via_proxy'  => $this->proxyBase !== '' ? 1 : 0,
                'tag'        => trim((string)($opts['tag'] ?? '')),
                'has_error'  => $err !== '' ? 1 : 0,
                'error'      => $err,
            ]);
        }
        return $data;
    }

    public function text(array $resp): string {
        $parts = $resp['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) return '';
        $out = '';
        foreach ($parts as $p) {
            if (is_array($p) && array_key_exists('text', $p)) $out .= (string)$p['text'];
        }
        return trim($out);
    }

    public function json(array $resp): ?array {
        $t = $this->text($resp);
        if ($t === '') return null;
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t) ?? $t;
        $t = preg_replace('/\s*```$/', '', $t) ?? $t;
        $j = json_decode($t, true);
        if (is_array($j)) return $j;
        if (preg_match('/\{[\s\S]*\}/', $t, $m)) {
            $j = json_decode($m[0], true);
            return is_array($j) ? $j : null;
        }
        return null;
    }
}
