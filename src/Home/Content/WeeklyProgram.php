<?php

declare(strict_types=1);

namespace App\Home\Content;

use App\Home\I18n\Lang;

/**
 * Недельная афиша: событие на каждый день недели + логика «сегодня».
 * Индекс дня — 0=Вс … 6=Сб (как JS getDay / PHP date('w')).
 * Тексты (title/time/note) и имена дней — из словаря Lang; фото — структура.
 *
 * Словарь — это стандартное расписание. Если на текущую неделю есть афиша из
 * Telegram ($override, см. App\Afisha), её карточки перекрывают стандартные
 * по дням; дни, которых в афише нет, остаются стандартными.
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

    /** Дни с киносеансами — кнопка ведёт на афишу фильмов, а не на бронь. */
    private const FILM_DAYS = [2, 4]; // Вт, Чт

    public function __construct(
        private readonly Lang $lang,
        private readonly int $today,
        string $reserveUrl,
        string $filmUrl = '',
        array $override = [],
    ) {
        foreach (self::IMAGE as $day => $image) {
            $card = $override[$day] ?? null;
            $isFilm = $filmUrl !== '' && ($card !== null ? $card['type'] === 'film' : in_array($day, self::FILM_DAYS, true));
            $this->byDay[$day] = new Event(
                $card['title'] ?? $lang->t("ev.d{$day}.title"),
                $card['time'] ?? $lang->t("ev.d{$day}.time"),
                $card['note'] ?? $lang->t("ev.d{$day}.note"),
                $image,
                $isFilm ? $filmUrl : $reserveUrl,
                $isFilm ? $lang->t('tonight.films') : '',
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

    /** Дата дня на текущей неделе (Пн→Вс), например «29.09». */
    public function dayDate(int $day): string
    {
        return date('d.m', strtotime('monday this week +' . (($day + 6) % 7) . ' days'));
    }

    public function dayFullName(int $day): string
    {
        return $this->lang->list('days.full')[$day] ?? '';
    }
}
