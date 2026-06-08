<?php

declare(strict_types=1);

namespace App\Home\Content;

use App\Home\I18n\Lang;

/**
 * Четыре «мира» комплекса. Структура (индексы, фото, URL, флаги) — здесь;
 * тексты (label/title/lead/tags/alt/кнопки) — из словаря Lang по локали.
 */
final class VenueDirectory
{
    public function __construct(
        private readonly Lang $lang,
        private readonly Contacts $contacts,
    ) {
    }

    /**
     * @return Venue[]
     */
    public function worlds(): array
    {
        $l = $this->lang;
        $c = $this->contacts;

        return [
            // 01 — ресторан: галерея блюд + меню/бронь.
            new Venue(
                index: '01',
                label: $l->t('world.restaurant.label'),
                titleHtml: $l->t('world.restaurant.title'),
                lead: $l->t('world.restaurant.lead'),
                tags: $l->list('world.restaurant.tags'),
                images: [
                    'food-cafe-01', 'food-cafe-07', 'food-cafe-02', 'food-cafe-04',
                    'food-cafe-08', 'food-cafe-03', 'food-cafe-05', 'food-cafe-06',
                ],
                imageAlt: $l->t('world.restaurant.alt'),
                linkLabel: $l->t('world.restaurant.link'),
                linkUrl: $c->menu,
                external: false,
                reverse: false,
                secondaryLabel: $l->t('world.restaurant.book'),
                secondaryUrl: $c->reserve,
            ),

            // 02 — баня (партнёр): одна ссылка.
            new Venue(
                index: '02',
                label: $l->t('world.banya.label'),
                titleHtml: $l->t('world.banya.title'),
                lead: $l->t('world.banya.lead'),
                tags: $l->list('world.banya.tags'),
                images: ['banya'],
                imageAlt: $l->t('world.banya.alt'),
                linkLabel: $l->t('world.banya.link'),
                linkUrl: $c->banyaSite,
                external: true,
                reverse: true,
            ),

            // 03 — GameZone (лазертаг + Archery Tag): одна ссылка.
            new Venue(
                index: '03',
                label: $l->t('world.gamezone.label'),
                titleHtml: $l->t('world.gamezone.title'),
                lead: $l->t('world.gamezone.lead'),
                tags: $l->list('world.gamezone.tags'),
                images: ['gamezone'],
                imageAlt: $l->t('world.gamezone.alt'),
                linkLabel: $l->t('world.gamezone.link'),
                linkUrl: $c->gamezoneSite,
                external: true,
                reverse: false,
            ),

            // 04 — детская локация (Ananas, Instagram): видео на десктопе, фото на моб.
            new Venue(
                index: '04',
                label: $l->t('world.kids.label'),
                titleHtml: $l->t('world.kids.title'),
                lead: $l->t('world.kids.lead'),
                tags: $l->list('world.kids.tags'),
                images: ['kids-1'],
                imageAlt: $l->t('world.kids.alt'),
                linkLabel: $l->t('world.kids.link'),
                linkUrl: $c->kidsInstagram,
                external: true,
                reverse: true,
                video: 'kids-ananas',
            ),
        ];
    }
}
