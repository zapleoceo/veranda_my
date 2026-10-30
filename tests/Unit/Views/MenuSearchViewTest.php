<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

final class MenuSearchViewTest extends TestCase
{
    public function testSearchControlsAreTranslatedAndEscaped(): void
    {
        foreach (['ru' => 'Поиск по меню', 'en' => 'Search menu', 'vi' => 'Tìm trong thực đơn', 'ko' => '메뉴 검색'] as $lang => $label) {
            $html = $this->render($lang, true);
            self::assertStringContainsString($label, $html);
            self::assertStringContainsString('data-menu-search-status role="status"', $html);
            self::assertStringContainsString('&lt;b&gt;Tea&lt;/b&gt;', $html);
            self::assertStringNotContainsString('<div class="item-title"><b>', $html);
            self::assertStringContainsString('/assets/js/menu-search.js?v=', $html);
        }
    }

    public function testEmptyMenuHasNoInactiveSearchControls(): void
    {
        self::assertStringNotContainsString('id="menuSearch"', $this->render('en', false));
    }

    private function render(string $lang, bool $hasItems): string
    {
        $seo = ['canonical' => '/menu', 'hreflang' => [], 'title' => 'Menu', 'description' => 'Menu'];
        $pageTitle = $menuLabel = 'Menu';
        $lastMenuSyncAt = null;
        $explicitLang = true;
        $groups = $hasItems ? [['label' => 'Drinks', 'cats' => [['label' => 'Tea', 'items' => [
            ['title' => '<b>Tea</b>', 'description' => '', 'image_url' => '', 'price_raw' => 100],
        ]]]]] : [];
        ob_start();
        require __DIR__ . '/../../../src/Views/menu_public.php';
        return (string) ob_get_clean();
    }
}
