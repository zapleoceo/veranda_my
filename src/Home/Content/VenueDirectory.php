<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Собирает три «мира» комплекса. Зависит от Contacts (DIP) — все URL/телефоны
 * приходят из единого источника правды, здесь их не дублируем.
 */
final class VenueDirectory
{
    public function __construct(private readonly Contacts $contacts)
    {
    }

    /**
     * @return Venue[]
     */
    public function worlds(): array
    {
        $c = $this->contacts;

        return [
            // 01 — сам ресторан: его собственные конверсии (меню + бронь).
            new Venue(
                number: '01 — Ресторан',
                titleHtml: 'Домашняя кухня <em>с европейским</em> акцентом',
                lead: 'Завтраки в тени деревьев, авторские бургеры, блины с творогом, '
                    . 'вафли, коктейли и свежее разливное пиво. Готовим то, по чему '
                    . 'скучаешь дома, — и то, что попробовал в путешествии.',
                tags: ['Завтраки', 'Домашняя кухня', 'Европейское', 'Коктейли', 'Свежее пиво'],
                image: 'food-breakfast',
                imageAlt: 'Завтрак-сет с лососем и омлетом на веранде',
                linkLabel: 'Открыть меню',
                linkUrl: $c->menu,
                external: false,
                reverse: false,
                secondaryLabel: 'Забронировать',
                secondaryUrl: $c->reserve,
            ),

            // 02 — баня: партнёр на той же поляне. РОВНО ОДНА ссылка.
            new Venue(
                number: '02 — Баня «Сила Духа»',
                titleHtml: 'Настоящая русская баня <em>на дровах</em>',
                lead: 'Горячая парная на дровах, холодная купель, опытные пармастера, '
                    . 'веники, чай с мёдом и квас. После — ужин на веранде, не вставая '
                    . 'со стула.',
                tags: ['Парная на дровах', 'Холодная купель', 'Пармастера', 'Чай с мёдом'],
                image: 'banya',
                imageAlt: 'Русская баня на дровах — парная с веником',
                linkLabel: 'Перейти на сайт бани',
                linkUrl: $c->banyaSite,
                external: true,
                reverse: true,
            ),

            // 03 — GameZone: лазертаг + Archery Tag + детская зона. РОВНО ОДНА ссылка.
            new Venue(
                number: '03 — GameZone',
                titleHtml: 'Лазертаг, <em>Archery Tag</em> и детская зона',
                lead: 'Archery Tag — лучный бой, как пейнтбол, но безопасный: луки с '
                    . 'мягкими наконечниками и инструктаж перед игрой. Плюс лазертаг, '
                    . 'квесты, BBQ-беседки и аниматор для детей. Мир приключений для '
                    . 'детей и взрослых — единственный такой в Нячанге.',
                tags: ['Archery Tag', 'Лазертаг', 'Квесты', 'Детская зона', 'BBQ-беседки'],
                image: 'gamezone',
                imageAlt: 'Archery Tag — лучный бой в GameZone',
                linkLabel: 'Перейти на сайт GameZone',
                linkUrl: $c->gamezoneSite,
                external: true,
                reverse: false,
            ),
        ];
    }
}
