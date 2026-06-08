<?php

declare(strict_types=1);

namespace App\Home\Content;

use App\Home\I18n\Lang;

/**
 * Копирайт страницы вне разметки — берёт строки из словаря Lang по локали.
 * Здесь же структура, не зависящая от языка (имена фото галереи).
 */
final class PageContent
{
    public function __construct(private readonly Lang $lang)
    {
    }

    public function heroTitleHtml(): string
    {
        return $this->lang->t('hero.title');
    }

    public function heroLead(): string
    {
        return $this->lang->t('hero.lead');
    }

    /** @return string[] */
    public function marquee(): array
    {
        return $this->lang->list('marquee');
    }

    /**
     * @return array<string,array{eyebrow:string,titleHtml:string,lead:string}>
     */
    public function heads(): array
    {
        $l = $this->lang;

        return [
            'tonight'  => ['eyebrow' => $l->t('tonight.eyebrow'),  'titleHtml' => $l->t('tonight.title'),  'lead' => $l->t('tonight.lead')],
            'worlds'   => ['eyebrow' => $l->t('worlds.eyebrow'),   'titleHtml' => $l->t('worlds.title'),   'lead' => $l->t('worlds.lead')],
            'bento'    => ['eyebrow' => $l->t('bento.eyebrow'),    'titleHtml' => $l->t('bento.title'),    'lead' => ''],
            'location' => ['eyebrow' => $l->t('location.eyebrow'), 'titleHtml' => $l->t('location.title'), 'lead' => $l->t('location.lead')],
        ];
    }

    /**
     * Фото bento-галереи (имена — структура; alt — из словаря).
     *
     * @return array<array{name:string,alt:string}>
     */
    public function gallery(): array
    {
        $alt = $this->lang->t('gallery.alt');

        return array_map(
            static fn (string $name): array => ['name' => $name, 'alt' => $alt],
            ['mountain-view', 'garden-table', 'lanterns-city', 'garden-path', 'hibiscus'],
        );
    }

    /**
     * @return array{text:string,cite:string}
     */
    public function galleryQuote(): array
    {
        return ['text' => $this->lang->t('bento.quote'), 'cite' => $this->lang->t('bento.cite')];
    }

    /**
     * @return array{title:string,lead:string}
     */
    public function gazebos(): array
    {
        return ['title' => $this->lang->t('gazebos.title'), 'lead' => $this->lang->t('gazebos.lead')];
    }

    public function hours(): string
    {
        return $this->lang->t('location.hours');
    }

    public function directions(): string
    {
        return $this->lang->t('location.directions');
    }
}
