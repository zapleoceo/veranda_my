<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Копирайт страницы вне разметки — единая точка для текстов hero, заголовков
 * секций, бегущей строки, галереи и беседок. Разметка (partials) обращается
 * сюда, а не хранит строки у себя.
 */
final class PageContent
{
    public function heroTitleHtml(): string
    {
        return 'Целый вечер<br><em>в горах</em><br>Нячанга';
    }

    public function heroLead(): string
    {
        return 'Ресторан с домашней кухней, баня на дровах, игры для всей семьи, '
            . 'живая музыка и кино под звёздами — на одной локации, в 10 минутах '
            . 'от центра города.';
    }

    /**
     * Пункты бегущей строки.
     *
     * @return string[]
     */
    public function marquee(): array
    {
        return [
            'Ресторан',
            'Баня на дровах',
            'Archery Tag · Лазертаг',
            'Детский клуб',
            'Live Music',
            'Кино под звёздами',
        ];
    }

    /**
     * Заголовки секций: eyebrow + titleHtml (с <em>) + lead.
     *
     * @return array<string,array{eyebrow:string,titleHtml:string,lead:string}>
     */
    public function heads(): array
    {
        return [
            'tonight' => [
                'eyebrow' => 'Живая афиша',
                'titleHtml' => 'Что сегодня <em>вечером</em>',
                'lead' => 'Каждый день недели — своё настроение. Вход на события '
                    . 'свободный, столик стоит забронировать заранее.',
            ],
            'worlds' => [
                'eyebrow' => 'Один комплекс',
                'titleHtml' => 'Четыре мира на одной <em>поляне</em>',
                'lead' => 'Позавтракали в тени горных деревьев → днём поиграли, дети '
                    . 'заняты в детском клубе → попарились в бане на закате → вечером '
                    . 'ужин с живой музыкой или кино под звёздами.',
            ],
            'bento' => [
                'eyebrow' => 'Атмосфера',
                'titleHtml' => 'Тёплый горный <em>вечер</em>',
                'lead' => '',
            ],
            'location' => [
                'eyebrow' => 'Как добраться',
                'titleHtml' => '10 минут <em>от центра</em>',
                'lead' => 'Veranda стоит на склоне горы: короткий серпантин — и ты в '
                    . 'саду с видом на Нячанг.',
            ],
        ];
    }

    /**
     * Фото для bento-галереи атмосферы.
     *
     * @return array<array{name:string,alt:string}>
     */
    public function gallery(): array
    {
        return [
            ['name' => 'mountain-view', 'alt' => 'Столик с видом на гору'],
            ['name' => 'garden-table',  'alt' => 'Столик в саду в обрамлении бугенвиллии'],
            ['name' => 'lanterns-city', 'alt' => 'Красные фонари на ветке и высотки Нячанга'],
            ['name' => 'garden-path',   'alt' => 'Дорожка в саду с оранжевым зонтиком'],
            ['name' => 'hibiscus',      'alt' => 'Жёлтый гибискус крупным планом'],
        ];
    }

    /**
     * @return array{text:string,cite:string}
     */
    public function galleryQuote(): array
    {
        return [
            'text' => '«Поднимаешься от шумного Нячанга по серпантину — и оказываешься '
                . 'в другом мире: ветер, виды, тишина, тепло.»',
            'cite' => '— гость, январь 2026',
        ];
    }

    /**
     * @return array{title:string,lead:string}
     */
    public function gazebos(): array
    {
        return [
            'title' => 'Беседки на компанию',
            'lead' => 'Уютные мини-беседки с тканевыми вуалями и низким столом. Хорошо '
                . 'для семейного вечера, дня рождения или просто долгого ужина под '
                . 'звёздами. Можно забронировать заранее.',
        ];
    }

    public function hours(): string
    {
        return 'Пн–Чт 10:00–22:00 · Пт–Вс 10:00–23:00';
    }

    public function directions(): string
    {
        return '~10 минут на такси или байке от центра города. Парковка на месте.';
    }
}
