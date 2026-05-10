<?php

declare(strict_types=1);

namespace App\Classes\TestAI\Service;

use App\Classes\TestAI\Repository\MenuRepository;

/**
 * Provides menu data from DB with a simple cache per request.
 * Only exposes selling prices — cost_raw is never selected in MenuRepository.
 */
class MenuService {
    private ?array $cache = null;

    public function __construct(private MenuRepository $repo) {}

    /** Returns formatted menu text ready for Gemini context. */
    public function getMenuText(string $lang = 'ru'): string {
        return MenuRepository::formatForPrompt($this->items($lang));
    }

    /** Returns raw items array (for tests / admin preview). */
    public function getItems(string $lang = 'ru'): array {
        return $this->items($lang);
    }

    private function items(string $lang): array {
        if ($this->cache === null) {
            $this->cache = $this->repo->getPublishedItems($lang);
        }
        return $this->cache;
    }
}
