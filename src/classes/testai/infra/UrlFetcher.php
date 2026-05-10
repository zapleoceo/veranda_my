<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Infra;

/**
 * Fetches a URL and returns plain text content.
 * Only allows veranda.my and the configured app domain.
 */
class UrlFetcher {
    private string $appDomain;

    public function __construct(string $appUrl = '') {
        $u = @parse_url(trim($appUrl));
        $this->appDomain = is_array($u) ? strtolower((string)($u['host'] ?? '')) : '';
    }

    public function isAllowed(string $url): bool {
        $u    = @parse_url(trim($url));
        $host = is_array($u) ? strtolower((string)($u['host'] ?? '')) : '';
        if ($host === '') return false;
        $allowed = array_filter(array_unique(['veranda.my', 'www.veranda.my', $this->appDomain]));
        return in_array($host, $allowed, true);
    }

    /** Fetch URL, strip HTML, return text. Returns '' on failure or not-allowed. */
    public function fetch(string $url, int $maxLen = 30000): string {
        $url = trim($url);
        if ($url === '') return '';
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
        if (!$this->isAllowed($url)) return '';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: veranda-ai-bot/2.0',
            'Accept: text/html,application/json;q=0.9,text/plain;q=0.8,*/*;q=0.1',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $code < 200 || $code >= 300) return '';
        if (strlen($body) > 400000) $body = substr($body, 0, 400000);

        // JSON response → pretty-print as text
        if (str_contains(strtolower($ct), 'application/json') || (ltrim($body)[0] ?? '') === '{') {
            $j = json_decode($body, true);
            if (is_array($j)) {
                $pretty = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                return is_string($pretty) ? $this->cap(trim($pretty), $maxLen) : '';
            }
        }

        return $this->htmlToText($body, $maxLen);
    }

    private function htmlToText(string $html, int $maxLen): string {
        $t = preg_replace('/<\s*(script|style|noscript)[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/i', '', $html) ?? $html;
        $t = preg_replace('/<\s*(br|br\/)\s*>/i', "\n", $t) ?? $t;
        $t = preg_replace('/<\s*\/?\s*(p|div|li|tr|h[1-6])[^>]*>/i', "\n", $t) ?? $t;
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = preg_replace('/[ \t]+/', ' ', $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return $this->cap(trim($t), $maxLen);
    }

    private function cap(string $s, int $max): string {
        $max = max(500, min(60000, $max));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
    }
}
