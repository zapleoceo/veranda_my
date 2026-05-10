<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Infra;

class TelegramClient {
    private string $token;
    private int $lastHttpCode = 0;
    private ?Logger $log;

    public function __construct(string $token, ?Logger $log = null) {
        $this->token = trim($token);
        $this->log   = $log;
    }

    public function hasToken(): bool {
        return $this->token !== '';
    }

    public function lastHttpCode(): int {
        return $this->lastHttpCode;
    }

    public function postJson(string $method, array $payload): ?array {
        if ($this->token === '') return null;
        $ch = curl_init("https://api.telegram.org/bot{$this->token}/{$method}");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $resp = curl_exec($ch);
        $this->lastHttpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($resp) || $resp === '') return null;
        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }

    public function getFileUrl(string $fileId): ?array {
        $info = $this->postJson('getFile', ['file_id' => $fileId]);
        if (!is_array($info) || empty($info['ok']) || !is_array($info['result'] ?? null)) return null;
        $filePath = (string)($info['result']['file_path'] ?? '');
        if ($filePath === '') return null;
        return [
            'url'       => "https://api.telegram.org/file/bot{$this->token}/{$filePath}",
            'file_path' => $filePath,
            'file_size' => $info['result']['file_size'] ?? null,
        ];
    }

    public function fetchBytes(string $url, int $timeout = 25): ?string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($resp) || $resp === '' || $code < 200 || $code >= 300) return null;
        return $resp;
    }

    public function sendMessage(string $chatId, string $html, ?int $replyToMessageId = null): bool {
        if ($this->token === '') return false;

        $chunks = $this->splitMessage($html, 4000);
        $allOk  = true;

        foreach ($chunks as $i => $chunk) {
            $payload = [
                'chat_id'                  => $chatId,
                'text'                     => $chunk,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ];
            if ($i === 0 && $replyToMessageId !== null && $replyToMessageId > 0) {
                $payload['reply_to_message_id'] = $replyToMessageId;
            }

            $r  = $this->postJson('sendMessage', $payload);
            $ok = is_array($r) && !empty($r['ok']);

            if (!$ok && $this->log) {
                $desc = is_array($r) ? (string)($r['description'] ?? '') : '';
                $this->log->error('telegram_send_failed', [
                    'http_code'   => $this->lastHttpCode,
                    'error_code'  => is_array($r) ? (string)($r['error_code'] ?? '') : '',
                    'description' => $desc,
                ]);
                // retry without reply_to if original message was deleted
                if ($i === 0 && $replyToMessageId !== null && stripos($desc, 'message to be replied not found') !== false) {
                    $p2 = $payload;
                    unset($p2['reply_to_message_id']);
                    $r2 = $this->postJson('sendMessage', $p2);
                    $ok = is_array($r2) && !empty($r2['ok']);
                }
            }
            if (!$ok) $allOk = false;
        }
        return $allOk;
    }

    private function splitMessage(string $html, int $maxLen): array {
        if (mb_strlen($html) <= $maxLen) return [$html];
        $chunks = [];
        while (mb_strlen($html) > $maxLen) {
            $slice = mb_substr($html, 0, $maxLen);
            // prefer to split at a newline
            $pos = mb_strrpos($slice, "\n");
            if ($pos === false || $pos < (int)($maxLen / 3)) $pos = $maxLen;
            $chunks[] = trim(mb_substr($html, 0, $pos));
            $html = trim(mb_substr($html, $pos));
        }
        if ($html !== '') $chunks[] = $html;
        return array_values(array_filter($chunks));
    }
}
