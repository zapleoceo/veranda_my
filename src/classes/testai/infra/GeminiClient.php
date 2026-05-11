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

    /** Single-turn call with a flat parts array. */
    public function generate(string $model, array $parts, array $opts = []): array {
        $payload = ['contents' => [['role' => 'user', 'parts' => $parts]]];
        return $this->call($model, $this->applyOpts($payload, $opts), $opts);
    }

    /**
     * Multi-turn call supporting Gemini function calling.
     * $contents: [['role'=>'user'|'model', 'parts'=>[...]], ...]
     * $toolDeclarations: array of Gemini functionDeclaration objects.
     */
    public function generateWithTools(string $model, array $contents, array $toolDeclarations, array $opts = []): array {
        $payload = ['contents' => $contents];
        if ($toolDeclarations) {
            $payload['tools']      = [['functionDeclarations' => $toolDeclarations]];
            $payload['toolConfig'] = ['functionCallingConfig' => ['mode' => 'AUTO']];
        }
        return $this->call($model, $this->applyOpts($payload, $opts), $opts);
    }

    /**
     * Extract function calls from a Gemini response.
     * @return array<array{name:string, args:array}>
     */
    public function extractToolCalls(array $resp): array {
        $parts = $resp['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) return [];
        $out = [];
        foreach ($parts as $p) {
            if (is_array($p) && isset($p['functionCall']) && is_array($p['functionCall'])) {
                $fc   = $p['functionCall'];
                $name = (string)($fc['name'] ?? '');
                $args = is_array($fc['args'] ?? null) ? $fc['args'] : [];
                if ($name !== '') $out[] = ['name' => $name, 'args' => $args];
            }
        }
        return $out;
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

    private function applyOpts(array $payload, array $opts): array {
        if (!empty($opts['system'])) {
            $payload['systemInstruction'] = ['parts' => [['text' => (string)$opts['system']]]];
        }
        $gen = [];
        if (array_key_exists('temperature', $opts))     $gen['temperature']      = (float)$opts['temperature'];
        if (array_key_exists('maxOutputTokens', $opts)) $gen['maxOutputTokens']  = (int)$opts['maxOutputTokens'];
        if (!empty($opts['responseMimeType']))           $gen['responseMimeType'] = (string)$opts['responseMimeType'];
        if ($gen) $payload['generationConfig'] = $gen;
        return $payload;
    }

    private function endpoint(string $model): string {
        if ($this->proxyBase !== '') {
            return $this->proxyBase . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
        }
        return 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);
    }

    private function call(string $model, array $payload, array $opts): array {
        $endpoint = $this->endpoint($model);

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
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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
                'http_code' => $code,
                'model'     => $model,
                'via_proxy' => $this->proxyBase !== '' ? 1 : 0,
                'tag'       => trim((string)($opts['tag'] ?? '')),
                'has_error' => $err !== '' ? 1 : 0,
                'error'     => $err,
            ]);
        }
        return $data;
    }
}
