<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Infra;

use App\Classes\TestAI\Repository\SettingsRepository;

/**
 * Fetches current dish availability from Poster POS.
 * Used to tell the bot which menu items are currently 86'd.
 * Results are cached in settings table for CACHE_TTL seconds.
 */
class PosterClient {
    private const CACHE_TEXT = 'poster_availability_text';
    private const CACHE_TS   = 'poster_availability_ts';
    private const CACHE_TTL  = 1800; // 30 min
    private const SPOT_ID    = 1;

    public function __construct(
        private string             $token,
        private string             $baseUrl,
        private SettingsRepository $settings
    ) {}

    public function isConfigured(): bool { return $this->token !== ''; }

    /** Returns formatted availability text (may be stale on API error). */
    public function getAvailabilityText(bool $forceRefresh = false): string {
        if (!$this->isConfigured()) return '';

        $cached = $this->settings->get(self::CACHE_TEXT);
        $ts     = $this->settings->get(self::CACHE_TS);

        if (!$forceRefresh && $cached !== '' && $ts !== '' && (time() - (int)strtotime($ts)) < self::CACHE_TTL) {
            return $cached;
        }

        $products = $this->fetchProducts();
        if ($products === null) return $cached; // fallback to stale cache

        $text = $this->formatAvailability($products);
        $this->settings->set(self::CACHE_TEXT, $text);
        $this->settings->set(self::CACHE_TS, date('c'));
        return $text;
    }

    /** Returns last refresh timestamp or empty string. */
    public function lastUpdatedAt(): string {
        return $this->settings->get(self::CACHE_TS);
    }

    private function fetchProducts(): ?array {
        $url = rtrim($this->baseUrl, '/') . '/menu.getProducts?token=' . urlencode($this->token);
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'veranda-ai-bot/2.0');
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($resp) || $code !== 200) return null;
        $data = json_decode($resp, true);
        return is_array($data['response'] ?? null) ? $data['response'] : null;
    }

    private function formatAvailability(array $products): string {
        $outOfStock = [];
        foreach ($products as $p) {
            if ((string)($p['hidden'] ?? '0') === '1') continue;
            $name = trim((string)($p['product_name'] ?? ''));
            if ($name === '') continue;

            $unavailable = (int)($p['out'] ?? 0) === 1;
            if (!$unavailable) {
                foreach (is_array($p['spots'] ?? null) ? $p['spots'] : [] as $spot) {
                    if ((int)($spot['spot_id'] ?? 0) === self::SPOT_ID && (int)($spot['visible'] ?? 1) === 0) {
                        $unavailable = true;
                        break;
                    }
                }
            }
            if ($unavailable) $outOfStock[] = $name;
        }

        return $outOfStock
            ? 'Сейчас временно нет в наличии: ' . implode(', ', $outOfStock) . '.'
            : 'Все позиции меню сейчас в наличии.';
    }
}
