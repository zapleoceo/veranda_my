<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Мета-данные страницы: <title>, description, Open Graph и Schema.org JSON-LD.
 * Отделено от копирайта тела (PageContent) — это разные обязанности.
 */
final class Seo
{
    public function __construct(
        public readonly string $canonical,
        public readonly string $ogImage,
        public readonly string $phone,
        public readonly string $menuUrl,
        public readonly string $reserveUrl,
    ) {
    }

    public function title(): string
    {
        return 'Veranda — ресторан в горах Нячанга, баня и игры';
    }

    public function description(): string
    {
        return 'Veranda Restaurant & Bar — ресторан на склоне в 10 минутах от центра '
            . 'Нячанга. Домашняя кухня, баня на дровах, игры для всей семьи, живая '
            . 'музыка и кино под звёздами. Вход на события свободный.';
    }

    public function ogTitle(): string
    {
        return 'Veranda — целый вечер впечатлений в горах Нячанга';
    }

    public function ogDescription(): string
    {
        return 'Ресторан, баня на дровах, игры для всей семьи, живая музыка и кино '
            . 'под звёздами. 10 минут от центра.';
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            'name' => 'Veranda Restaurant & Bar',
            'url' => $this->canonical,
            'image' => $this->ogImage,
            'telephone' => $this->phone,
            'priceRange' => '$$',
            'servesCuisine' => ['Slavic', 'European', 'Vietnamese'],
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Nha Trang',
                'addressRegion' => 'Khánh Hòa',
                'addressCountry' => 'Vietnam',
            ],
            'hasMenu' => $this->menuUrl,
            'potentialAction' => [
                '@type' => 'ReserveAction',
                'target' => $this->reserveUrl,
            ],
        ];
    }
}
