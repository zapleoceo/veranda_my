<?php

namespace App\Classes;

class TestAILogger {
    private string $file;

    public function __construct(string $dir, string $fileName = 'testai.log') {
        $dir = rtrim(trim($dir), '/\\');
        if ($dir === '') $dir = '.';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $this->file = $dir . '/' . ltrim($fileName, '/\\');
    }

    public function filePath(): string {
        return $this->file;
    }

    public function info(string $event, array $ctx = []): void {
        $this->write('info', $event, $ctx);
    }

    public function error(string $event, array $ctx = []): void {
        $this->write('error', $event, $ctx);
    }

    private function write(string $level, string $event, array $ctx): void {
        $safe = $this->sanitize($ctx);
        $line = json_encode([
            'ts' => date('c'),
            'level' => $level,
            'event' => $event,
            'ctx' => $safe,
        ], JSON_UNESCAPED_UNICODE);
        if (!is_string($line)) return;
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function sanitize(array $ctx): array {
        $out = [];
        foreach ($ctx as $k => $v) {
            $key = is_string($k) ? $k : (string)$k;
            $lk = strtolower($key);
            if (strpos($lk, 'token') !== false || strpos($lk, 'secret') !== false || strpos($lk, 'pass') !== false || strpos($lk, 'key') !== false) {
                $out[$key] = '[redacted]';
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$key] = $v;
                continue;
            }
            if (is_array($v)) {
                $out[$key] = $this->sanitize($v);
                continue;
            }
            $out[$key] = '[' . gettype($v) . ']';
        }
        return $out;
    }
}

class TestAIDbSchema {
    public function ensure(Database $db, string $tRaw, string $tDaily, string $tSettings, string $tKb = ''): void {
        try {
            $pdo = $db->getPdo();
            $pdo->exec("CREATE TABLE IF NOT EXISTS {$tRaw} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                tg_chat_id BIGINT NOT NULL,
                tg_chat_type VARCHAR(16) NOT NULL,
                tg_chat_title VARCHAR(255) NULL,
                tg_message_id BIGINT NOT NULL,
                tg_user_id BIGINT NULL,
                tg_username VARCHAR(64) NULL,
                tg_name VARCHAR(128) NULL,
                received_at DATETIME NOT NULL,
                text TEXT NOT NULL,
                media_type VARCHAR(16) NULL,
                media_file_id VARCHAR(255) NULL,
                media_file_unique_id VARCHAR(255) NULL,
                media_mime VARCHAR(128) NULL,
                media_duration_sec INT NULL,
                media_text TEXT NULL,
                meta_json TEXT NULL,
                UNIQUE KEY uniq_chat_msg (tg_chat_id, tg_message_id),
                KEY idx_received_at (received_at),
                KEY idx_chat_time (tg_chat_id, received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS {$tDaily} (
                day DATE NOT NULL PRIMARY KEY,
                summary_text TEXT NOT NULL,
                events_json TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $pdo->exec("CREATE TABLE IF NOT EXISTS {$tSettings} (
                k VARCHAR(64) NOT NULL PRIMARY KEY,
                v TEXT NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            if ($tKb !== '') {
                $pdo->exec("CREATE TABLE IF NOT EXISTS {$tKb} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    source_url VARCHAR(512) NULL,
                    content MEDIUMTEXT NOT NULL,
                    tags VARCHAR(255) NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    KEY idx_active (is_active),
                    KEY idx_updated_at (updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }
        } catch (\Throwable $e) {
        }
    }
}

class TestAITelegramClient {
    private string $token;
    private int $lastHttpCode = 0;
    private string $lastRaw = '';
    private ?TestAILogger $logger = null;

    public function __construct(string $token, ?TestAILogger $logger = null) {
        $this->token = trim($token);
        $this->logger = $logger;
    }

    public function hasToken(): bool {
        return $this->token !== '';
    }

    public function lastHttpCode(): int {
        return $this->lastHttpCode;
    }

    public function lastRaw(): string {
        return $this->lastRaw;
    }

    public function postJson(string $method, array $payload): ?array {
        if ($this->token === '') return null;
        $apiBase = "https://api.telegram.org/bot{$this->token}";
        $ch = curl_init("{$apiBase}/{$method}");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->lastHttpCode = (int)$code;
        $this->lastRaw = is_string($resp) ? $resp : '';
        if (!is_string($resp) || $resp === '') return null;
        $data = json_decode($resp, true);
        return is_array($data) ? $data : null;
    }

    public function getWebhookInfo(): ?array {
        return $this->postJson('getWebhookInfo', []);
    }

    public function getFileUrl(string $fileId): ?array {
        $info = $this->postJson('getFile', ['file_id' => $fileId]);
        if (!is_array($info) || empty($info['ok']) || !is_array($info['result'] ?? null)) return null;
        $filePath = (string)($info['result']['file_path'] ?? '');
        if ($filePath === '') return null;
        $url = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
        return [
            'url' => $url,
            'file_path' => $filePath,
            'file_size' => $info['result']['file_size'] ?? null,
        ];
    }

    public function fetchBytes(string $url, int $timeout = 25): ?string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($resp) || $resp === '' || $code < 200 || $code >= 300) return null;
        return $resp;
    }

    public function sendMessage(string $chatId, string $html, ?int $replyToMessageId = null): bool {
        if ($this->token === '') return false;
        $payload = [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];
        if ($replyToMessageId !== null && $replyToMessageId > 0) $payload['reply_to_message_id'] = $replyToMessageId;
        $r = $this->postJson('sendMessage', $payload);
        $ok = is_array($r) && !empty($r['ok']);
        if (!$ok) {
            $desc = is_array($r) ? (string)($r['description'] ?? '') : '';
            $err = is_array($r) ? (string)($r['error_code'] ?? '') : '';
            if ($this->logger) {
                $this->logger->error('telegram_send_failed', [
                    'http_code' => $this->lastHttpCode,
                    'error_code' => $err,
                    'description' => $desc,
                ]);
            }

            if ($replyToMessageId !== null && $replyToMessageId > 0 && stripos($desc, 'message to be replied not found') !== false) {
                $payload2 = $payload;
                unset($payload2['reply_to_message_id']);
                $r2 = $this->postJson('sendMessage', $payload2);
                $ok2 = is_array($r2) && !empty($r2['ok']);
                if ($this->logger) {
                    $desc2 = is_array($r2) ? (string)($r2['description'] ?? '') : '';
                    $err2 = is_array($r2) ? (string)($r2['error_code'] ?? '') : '';
                    $this->logger->info('telegram_send_retry_no_reply', [
                        'ok' => $ok2 ? 1 : 0,
                        'http_code' => $this->lastHttpCode,
                        'error_code' => $err2,
                        'description' => $desc2,
                    ]);
                }
                return $ok2;
            }
        }
        return $ok;
    }
}

class TestAIGeminiClient {
    private string $apiKey;
    private string $proxyBase;
    private string $proxyKey;
    private ?TestAILogger $logger = null;

    public function __construct(string $apiKey, string $proxyBase, string $proxyKey, ?TestAILogger $logger = null) {
        $this->apiKey = trim($apiKey);
        $this->proxyBase = rtrim(trim($proxyBase), '/');
        $this->proxyKey = trim($proxyKey);
        $this->logger = $logger;
    }

    public function canCall(): bool {
        return $this->proxyBase !== '' || $this->apiKey !== '';
    }

    public function generate(string $model, array $parts, array $opts = []): array {
        $endpoint = '';
        if ($this->proxyBase !== '') {
            $endpoint = $this->proxyBase . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
        } else {
            $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);
        }

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => $parts,
                ],
            ],
        ];
        if (!empty($opts['system'])) {
            $payload['systemInstruction'] = ['parts' => [['text' => (string)$opts['system']]]];
        }
        if (!empty($opts['temperature']) || array_key_exists('temperature', $opts)) {
            $payload['generationConfig'] = $payload['generationConfig'] ?? [];
            $payload['generationConfig']['temperature'] = (float)$opts['temperature'];
        }
        if (!empty($opts['maxOutputTokens']) || array_key_exists('maxOutputTokens', $opts)) {
            $payload['generationConfig'] = $payload['generationConfig'] ?? [];
            $payload['generationConfig']['maxOutputTokens'] = (int)$opts['maxOutputTokens'];
        }
        if (!empty($opts['responseMimeType'])) {
            $payload['generationConfig'] = $payload['generationConfig'] ?? [];
            $payload['generationConfig']['responseMimeType'] = (string)$opts['responseMimeType'];
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        $headers = ['Content-Type: application/json'];
        if ($this->proxyBase !== '' && $this->proxyKey !== '') {
            $headers[] = 'X-Veranda-Key: ' . $this->proxyKey;
            $headers[] = 'X-Gemini-Proxy-Key: ' . $this->proxyKey;
            $headers[] = 'X-Proxy-Key: ' . $this->proxyKey;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode(is_string($resp) ? $resp : '', true);
        if (!is_array($data)) $data = [];
        $data['_http_code'] = (int)$code;
        if ($this->logger) {
            $err = '';
            if (is_array($data['error'] ?? null)) $err = trim((string)($data['error']['message'] ?? ''));
            if (mb_strlen($err) > 500) $err = mb_substr($err, 0, 500) . '…';
            $tag = '';
            if (isset($opts['tag'])) $tag = trim((string)$opts['tag']);
            $this->logger->info('gemini_http', [
                'http_code' => (int)$code,
                'model' => $model,
                'via_proxy' => $this->proxyBase !== '' ? 1 : 0,
                'tag' => $tag,
                'has_error' => $err !== '' ? 1 : 0,
                'error' => $err,
            ]);
        }
        return $data;
    }

    public function text(array $resp): string {
        if (!isset($resp['candidates'][0]['content']['parts']) || !is_array($resp['candidates'][0]['content']['parts'])) return '';
        $out = '';
        foreach ($resp['candidates'][0]['content']['parts'] as $p) {
            if (is_array($p) && array_key_exists('text', $p)) $out .= (string)$p['text'];
        }
        return trim($out);
    }

    public function json(array $resp): ?array {
        $t = $this->text($resp);
        if ($t === '') return null;
        $t = preg_replace('/^```(?:json)?\s*/i', '', $t);
        $t = preg_replace('/\s*```$/', '', $t);
        $j = json_decode($t, true);
        if (is_array($j)) return $j;
        if (preg_match('/\{[\s\S]*\}/', $t, $m)) {
            $j = json_decode($m[0], true);
            return is_array($j) ? $j : null;
        }
        return null;
    }
}

class TestAIHtmlSanitizer {
    public function sanitizeHtml(string $html): string {
        $html = trim($html);
        if ($html === '') return '';
        if (mb_strlen($html) > 200000) $html = mb_substr($html, 0, 200000);

        $allowedTags = ['div','p','br','strong','b','em','i','ul','ol','li','h2','h3','a','span'];
        $allowed = '<' . implode('><', $allowedTags) . '>';
        $html = strip_tags($html, $allowed);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*') as $el) {
            if (!$el instanceof \DOMElement) continue;
            $tag = strtolower($el->tagName);
            $toRemove = [];
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                $val = (string)$attr->value;
                if (strpos($name, 'on') === 0) { $toRemove[] = $attr->name; continue; }
                if ($tag === 'a') {
                    if (!in_array($name, ['href','target','rel'], true)) { $toRemove[] = $attr->name; continue; }
                    if ($name === 'href') {
                        $v = trim($val);
                        if ($v === '' || preg_match('/^\s*javascript:/i', $v)) { $toRemove[] = $attr->name; continue; }
                    }
                    if ($name === 'target') $el->setAttribute('target', '_blank');
                    if ($name === 'rel') $el->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $toRemove[] = $attr->name;
                }
            }
            foreach ($toRemove as $n) $el->removeAttribute($n);
            if ($tag === 'a') {
                if (!$el->hasAttribute('rel')) $el->setAttribute('rel', 'noopener noreferrer');
                if (!$el->hasAttribute('target')) $el->setAttribute('target', '_blank');
            }
        }

        $out = $doc->saveHTML();
        return is_string($out) ? trim($out) : '';
    }

    public function sanitizeTelegramHtml(string $html): string {
        $html = trim($html);
        if ($html === '') return '';
        if (mb_strlen($html) > 50000) $html = mb_substr($html, 0, 50000);

        $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
        if ($html === null) $html = '';
        $html = str_ireplace(
            ['</p>', '</div>', '</li>', '</h2>', '</h3>'],
            ["\n", "\n", "\n", "\n", "\n"],
            $html
        );
        $html = preg_replace('/<\s*(p|div|ul|ol|li|h2|h3)\b[^>]*>/i', "\n", $html);
        if ($html === null) $html = '';
        $html = preg_replace("/\n{3,}/", "\n\n", $html);
        if ($html === null) $html = '';

        $allowedTags = ['b','strong','i','em','u','ins','s','strike','del','code','pre','a'];
        $allowed = '<' . implode('><', $allowedTags) . '>';
        $html = strip_tags($html, $allowed);

        $html = str_replace("\r\n", "\n", $html);
        $html = str_replace("\r", "\n", $html);
        $nlToken = '___TG_NL___';
        $html = str_replace("\n", $nlToken, $html);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*') as $el) {
            if (!$el instanceof \DOMElement) continue;
            $tag = strtolower($el->tagName);
            $toRemove = [];
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                $val = (string)$attr->value;
                if (strpos($name, 'on') === 0) { $toRemove[] = $attr->name; continue; }
                if ($tag === 'a') {
                    if (!in_array($name, ['href'], true)) { $toRemove[] = $attr->name; continue; }
                    $v = trim($val);
                    if ($v === '' || preg_match('/^\s*javascript:/i', $v)) { $toRemove[] = $attr->name; continue; }
                } else {
                    $toRemove[] = $attr->name;
                }
            }
            foreach ($toRemove as $n) $el->removeAttribute($n);
        }

        $out = $doc->saveHTML();
        if (!is_string($out)) return '';
        $out = trim($out);
        $out = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', $out) ?? $out;
        $out = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $out) ?? $out;
        $out = preg_replace('/^\s*<html\b[^>]*>\s*<body\b[^>]*>\s*/i', '', $out) ?? $out;
        $out = preg_replace('/\s*<\/body>\s*<\/html>\s*$/i', '', $out) ?? $out;
        $out = strip_tags($out, $allowed);
        $out = str_replace($nlToken, "\n", $out);
        $out = preg_replace("/\n{3,}/", "\n\n", $out) ?? $out;
        return trim($out);
    }
}
