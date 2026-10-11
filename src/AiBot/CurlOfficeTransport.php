<?php

declare(strict_types=1);

namespace App\AiBot;

/** curl-транспорт: без редиректов, проверка TLS включена; в ошибках только текст curl. */
final class CurlOfficeTransport implements OfficeHttpTransport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new OfficeTransportException('curl init failed');
        }
        $respHeaders = [];
        $lines = [];
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$respHeaders): int {
                $p = explode(':', $h, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($h);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new OfficeTransportException('office transport: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $out];
    }
}
