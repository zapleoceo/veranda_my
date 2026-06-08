<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Собирает «миры» комплекса. Зависит от Contacts (DIP) — все URL/телефоны
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
            // 01 — ресторан: галерея блюд + собственные конверсии (меню + бронь).
            new Venue(
                index: '01',
                label: 'Ресторан',
                titleHtml: 'Домашняя кухня <em>с европейским</em> акцентом',
                lead: 'Завтраки в тени деревьев, борщ и солянка, авторские бургеры, '
                    . 'паштеты и салаты, коктейли и свежее разливное пиво. Готовим то, '
                    . 'по чему скучаешь дома, — и то, что попробовал в путешествии.',
                tags: ['Завтраки', 'Домашняя кухня', 'Европейское', 'Коктейли', 'Свежее пиво'],
                images: [
                    'food-cafe-01', 'food-cafe-07', 'food-cafe-02', 'food-cafe-04',
                    'food-cafe-08', 'food-cafe-03', 'food-cafe-05', 'food-cafe-06',
                ],
                imageAlt: 'Блюда кухни Veranda',
                linkLabel: 'Открыть меню',
                linkUrl: $c->menu,
                external: false,
                reverse: false,
                secondaryLabel: 'Забронировать',
                secondaryUrl: $c->reserve,
            ),

            // 02 — баня: партнёр на той же поляне. РОВНО ОДНА ссылка.
            new Venue(
                index: '02',
                label: 'Баня «Сила Духа»',
                titleHtml: 'Настоящая русская баня <em>на дровах</em>',
                lead: 'Горячая парная на дровах, холодная купель, опытные пармастера, '
                    . 'веники, чай с мёдом и квас. После — ужин на веранде, не вставая '
                    . 'со стула.',
                tags: ['Парная на дровах', 'Холодная купель', 'Пармастера', 'Чай с мёдом'],
                images: ['banya'],
                imageAlt: 'Русская баня на дровах — парная с веником',
                linkLabel: 'Перейти на сайт бани',
                linkUrl: $c->banyaSite,
                external: true,
                reverse: true,
            ),

            // 03 — GameZone: лазертаг + Archery Tag (без детской зоны — она отдельным
            // миром ниже). РОВНО ОДНА ссылка.
            new Venue(
                index: '03',
                label: 'GameZone',
                titleHtml: 'Лазертаг и <em>Archery Tag</em>',
                lead: 'Archery Tag — лучный бой, как пейнтбол, но безопасный: луки с '
                    . 'мягкими наконечниками и инструктаж перед игрой. Плюс лазертаг и '
                    . 'квесты. Командные игры для компании и корпоратива — единственные '
                    . 'такие в Нячанге.',
                tags: ['Archery Tag', 'Лазертаг', 'Квесты', 'BBQ-беседки', 'Корпоративы'],
                images: ['gamezone'],
                imageAlt: 'Archery Tag — лучный бой в GameZone',
                linkLabel: 'Перейти на сайт GameZone',
                linkUrl: $c->gamezoneSite,
                external: true,
                reverse: false,
            ),

            // 04 — детская локация (Ananas party): отдельный партнёр, сайта нет —
            // ссылка на Instagram. РОВНО ОДНА ссылка.
            new Venue(
                index: '04',
                label: 'Детская локация',
                titleHtml: 'Игры, творчество и <em>детские праздники</em>',
                lead: 'Детский клуб с аниматором: игры и мастер-классы, рисование, '
                    . 'дискотеки со светомузыкой и сказкотерапия. Пока дети заняты делом '
                    . '— родители отдыхают в ресторане или бане. Дни рождения и праздники '
                    . 'под ключ: свой сценарий, шоу мыльных пузырей и квесты.',
                tags: ['Аниматор', 'Мастер-классы', 'Дни рождения', 'Сказкотерапия', 'Дискотека'],
                images: ['kids-1'],
                imageAlt: 'Детское игровое пространство — игрушки, шары, фотозона',
                video: 'kids-ananas',
                linkLabel: 'Смотреть в Instagram',
                linkUrl: $c->kidsInstagram,
                external: true,
                reverse: true,
            ),
        ];
    }
}
