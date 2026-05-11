<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Infra;

use App\Classes\TestAI\Repository\SettingsRepository;

/**
 * Fetches menu data from Poster POS.
 * Products are cached in settings table for CACHE_TTL seconds.
 * Provides: availability text + dish composition lookup.
 */
class PosterClient {
    private const CACHE_TEXT     = 'poster_availability_text';
    private const CACHE_PRODUCTS = 'poster_products_json';
    private const CACHE_TS       = 'poster_availability_ts';
    private const CACHE_TTL      = 1800; // 30 min
    private const SPOT_ID        = 1;

    public function __construct(
        private string             $token,
        private string             $baseUrl,
        private SettingsRepository $settings
    ) {}

    public function isConfigured(): bool { return $this->token !== ''; }

    /** Returns formatted availability text (may be stale on API error). */
    public function getAvailabilityText(bool $forceRefresh = false): string {
        if (!$this->isConfigured()) return '';
        $products = $this->getCachedProducts($forceRefresh);
        if ($products === null) return $this->settings->get(self::CACHE_TEXT);
        return $this->formatAvailability($products);
    }

    /**
     * Find a dish by name and return its ingredient list.
     * Uses fuzzy matching via similar_text().
     */
    public function getDishComposition(string $dishName): string {
        if (!$this->isConfigured()) return '';
        $products = $this->getCachedProducts();
        if ($products === null) return 'Нет доступа к POS системе.';

        $needle  = mb_strtolower(trim($dishName));
        $best    = null;
        $bestPct = 0.0;

        foreach ($products as $p) {
            $name = mb_strtolower(trim((string)($p['product_name'] ?? '')));
            if ($name === '') continue;
            similar_text($needle, $name, $pct);
            // boost exact substring match
            if (str_contains($name, $needle) || str_contains($needle, $name)) $pct = max($pct, 80.0);
            if ($pct > $bestPct) { $bestPct = $pct; $best = $p; }
        }

        if ($best === null || $bestPct < 45) return 'Блюдо не найдено в POS системе.';

        $ingredients = is_array($best['ingredients'] ?? null) ? $best['ingredients'] : [];
        if (!$ingredients) {
            $name = trim((string)($best['product_name'] ?? $dishName));
            return "Состав «{$name}» не указан в POS системе.";
        }

        $parts = [];
        foreach ($ingredients as $ing) {
            $iName  = trim((string)($ing['ingredient_name'] ?? ''));
            $brutto = trim((string)($ing['brutto'] ?? ''));
            $unit   = trim((string)($ing['ingredient_unit'] ?? ''));
            if ($iName === '') continue;
            $parts[] = $brutto !== '' ? "{$iName} {$brutto}{$unit}" : $iName;
        }

        $name = trim((string)($best['product_name'] ?? $dishName));
        return "Состав «{$name}»: " . implode(', ', $parts) . '.';
    }

    /** Returns last refresh timestamp or empty string. */
    public function lastUpdatedAt(): string {
        return $this->settings->get(self::CACHE_TS);
    }

    private function getCachedProducts(bool $forceRefresh = false): ?array {
        $ts  = $this->settings->get(self::CACHE_TS);
        $raw = $this->settings->get(self::CACHE_PRODUCTS);

        if (!$forceRefresh && $raw !== '' && $ts !== '' && (time() - (int)strtotime($ts)) < self::CACHE_TTL) {
            $products = json_decode($raw, true);
            if (is_array($products)) return $products;
        }

        $products = $this->fetchProducts();
        if ($products === null) {
            // Return stale data rather than nothing
            $stale = $raw !== '' ? json_decode($raw, true) : null;
            return is_array($stale) ? $stale : null;
        }

        $text = $this->formatAvailability($products);
        $this->settings->set(self::CACHE_PRODUCTS, json_encode($products, JSON_UNESCAPED_UNICODE) ?: '');
        $this->settings->set(self::CACHE_TEXT, $text);
        $this->settings->set(self::CACHE_TS, date('c'));
        return $products;
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
