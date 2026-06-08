<?php

declare(strict_types=1);

namespace App\Home\Content;

use App\Home\I18n\Lang;

/**
 * Недельная афиша: событие на каждый день недели + логика «сегодня».
 * Индекс дня — 0=Вс … 6=Сб (как JS getDay / PHP date('w')).
 * Тексты (title/time/note) и имена дней — из словаря Lang; фото — структура.
 */
final class WeeklyProgram
{
    /** @var array<int,Event> */
    private array $byDay = [];

    /** Фон featured-карточки по дню (структура, не зависит от языка). */
    private const IMAGE = [
        1 => 'gazebo-inside',
        2 => 'lanterns-city',
        3 => 'hero-terrace',
        4 => 'garden-path',
        5 => 'hero-lanterns',
        6 => 'mountain-view',
        0 => 'gazebo-outside',
    ];

    public function __construct(
        private readonly Lang $lang,
        private readonly int $today,
        string $reserveUrl,
    ) {
        foreach (self::IMAGE as $day => $image) {
            $this->byDay[$day] = new Event(
                $lang->t("ev.d{$day}.title"),
                $lang->t("ev.d{$day}.time"),
                $lang->t("ev.d{$day}.note"),
                $image,
                $reserveUrl,
            );
        }
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
     * @return array<int,Event>
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
        return $this->lang->list('days.short')[$day] ?? '';
    }

    public function dayFullName(int $day): string
    {
        return $this->lang->list('days.full')[$day] ?? '';
    }
}
