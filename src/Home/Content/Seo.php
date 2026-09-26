<?php

declare(strict_types=1);

namespace App\Home\Content;

use App\Home\I18n\Lang;
use App\Home\I18n\Locale;

/**
 * Мета-данные страницы по локали: title/description/OG, canonical, hreflang-
 * альтернативы и расширенный Schema.org JSON-LD (для SEO и ИИ-краулеров).
 */
final class Seo
{
    public function __construct(
        private readonly Lang $lang,
        public readonly string $locale,
        private readonly string $base,
        private readonly Contacts $contacts,
    ) {
    }

    public function canonical(): string
    {
        return $this->base . '/' . $this->locale . '/';
    }

    public function ogImage(): string
    {
        return $this->base . '/assets/img/home/hero-terrace-1400.webp';
    }

    /**
     * hreflang-альтернативы: код языка → URL.
     *
     * @return array<string,string>
     */
    public function alternates(): array
    {
        $out = [];
        foreach (Locale::SUPPORTED as $loc) {
            $out[$loc] = $this->base . '/' . $loc . '/';
        }

        return $out;
    }

    public function xDefault(): string
    {
        return $this->base . '/' . Locale::FALLBACK . '/';
    }

    public function title(): string
    {
        return $this->lang->t('seo.title');
    }

    public function description(): string
    {
        return $this->lang->t('seo.description');
    }

    public function ogTitle(): string
    {
        return $this->lang->t('seo.ogTitle');
    }

    public function ogDescription(): string
    {
        return $this->lang->t('seo.ogDescription');
    }

    public function ogLocale(): string
    {
        return ['en' => 'en_US', 'ru' => 'ru_RU', 'vi' => 'vi_VN'][$this->locale] ?? 'en_US';
    }

    /**
     * Schema.org FAQPage — из тех же пар «вопрос-ответ», что и видимый блок на странице.
     * Сниппета в Google это не даст (FAQ-rich-results отключены), но делает Q&A
     * машиночитаемыми для ИИ-краулеров.
     *
     * @param array<array{q:string,a:string}> $faq
     * @return array<string,mixed>
     */
    public function faqJsonLd(array $faq): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            '@id' => $this->canonical() . '#faq',
            'inLanguage' => $this->locale,
            'mainEntity' => array_map(
                static fn (array $i): array => [
                    '@type' => 'Question',
                    'name' => $i['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['a']],
                ],
                $faq,
            ),
        ];
    }

    /**
     * Schema.org Restaurant — расширенно: адрес, гео, часы, кухни, соцсети, бронь.
     *
     * @return array<string,mixed>
     */
    public function jsonLd(): array
    {
        $c = $this->contacts;

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Restaurant',
            '@id' => $this->base . '/#restaurant',
            'name' => 'Veranda Restaurant & Bar',
            'url' => $this->canonical(),
            'image' => $this->ogImage(),
            'description' => $this->description(),
            'inLanguage' => $this->locale,
            'telephone' => $c->phone,
            'priceRange' => '100,000-200,000 VND',
            'servesCuisine' => ['European', 'Russian', 'Home cooking'],
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => 'Trần Khát Chân, Đường Đệ',
                'addressLocality' => 'Nha Trang',
                'addressRegion' => 'Khánh Hòa',
                'postalCode' => '650000',
                'addressCountry' => 'VN',
            ],
            'geo' => [
                '@type' => 'GeoCoordinates',
                'latitude' => $c->mapLat,
                'longitude' => $c->mapLng,
            ],
            'hasMap' => $c->maps,
            'openingHoursSpecification' => [
                ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday'], 'opens' => '10:00', 'closes' => '22:00'],
                ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Friday', 'Saturday', 'Sunday'], 'opens' => '10:00', 'closes' => '23:00'],
            ],
            'sameAs' => [$c->instagram, 'https://www.facebook.com/vngamezone/', $c->telegram],
            // schema.org помечает menu как устаревшее в пользу hasMenu, но документация
            // Google описывает только menu — держим оба, это один и тот же URL.
            'hasMenu' => $this->base . '/links/menu',
            'menu' => $this->base . '/links/menu',
            // Нативный Boolean: строка "True" разметкой читается как текст, а не как «да».
            'acceptsReservations' => true,
            'potentialAction' => [
                '@type' => 'ReserveAction',
                'target' => $this->base . '/tr3/',
            ],
        ];
    }
}
