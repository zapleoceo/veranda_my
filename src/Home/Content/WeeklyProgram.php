<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Недельная афиша: одно событие на каждый день недели + логика «сегодня».
 *
 * Индекс дня — 0=Вс … 6=Сб, как у JS Date.getDay() и PHP date('w'),
 * чтобы серверный и клиентский расчёт «сегодня» совпадали.
 *
 * У каждого события — своя картинка (фон featured-карточки) и ссылка кнопки:
 * клик по дню в сетке меняет featured (текст + фон + ссылку). Ссылка по
 * умолчанию ведёт на бронь; задать индивидуальную — поменять url события.
 */
final class WeeklyProgram
{
    /** @var array<int,Event> событие по индексу дня недели (0..6) */
    private array $byDay;

    public function __construct(private readonly int $today, string $reserveUrl)
    {
        $this->byDay = [
            1 => new Event('Настольные игры',    'весь вечер',    'Бункер, Тайный Гитлер, Мафия, Uno — бесплатно. Приходите своей компанией.', 'gazebo-inside', $reserveUrl),
            2 => new Event('Кино под звёздами',  '18:00 · 20:00', 'Детский и взрослый сеансы',      'lanterns-city', $reserveUrl),
            3 => new Event('Live Music',         '19:00',         'Каверы англоязычных хитов',      'hero-terrace',  $reserveUrl),
            4 => new Event('Кино под звёздами',  '18:00 · 20:00', 'Детский и взрослый сеансы',      'garden-path',   $reserveUrl),
            5 => new Event('Live Music',         '19:00',         'Группы чередуются: The Pennywort, Улик, Рядновы, BiBi Duo', 'hero-lanterns', $reserveUrl),
            6 => new Event('Живая музыка',       '19:00',         'Группы чередуются: The Pennywort, Улик, Рядновы, BiBi Duo', 'mountain-view', $reserveUrl),
            0 => new Event('Вечер живой музыки', '19:00',         'Группы чередуются: The Pennywort, Улик, Рядновы, BiBi Duo', 'gazebo-outside', $reserveUrl),
        ];
    }

    public function todayIndex(): int
    {
        return $this->today;
    }

    public function today(): Event
    {
        return $this->byDay[$this->today] ?? $this->byDay[0];
    }

    /**
     * Неделя в порядке Пн→Вс для сетки.
     *
     * @return array<int,Event> событие, ключ — индекс дня (0..6)
     */
    public function week(): array
    {
        $out = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
            $out[$d] = $this->byDay[$d];
        }

        return $out;
    }

    public function dayName(int $day): string
    {
        return ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'][$day] ?? '';
    }

    public function dayFullName(int $day): string
    {
        return ['Воскресенье', 'Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница', 'Суббота'][$day] ?? '';
    }
}
