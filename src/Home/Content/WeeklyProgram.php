<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Недельная афиша: одно событие на каждый день недели + логика «сегодня».
 *
 * Индекс дня — 0=Вс … 6=Сб, как у JS Date.getDay() и PHP date('w'),
 * чтобы серверный и клиентский расчёт «сегодня» совпадали.
 *
 * Текущий день инъектируется в конструктор (а не берётся из глобального
 * времени внутри) — так класс детерминирован и тестируем.
 */
final class WeeklyProgram
{
    /** @var array<int,Event> событие по индексу дня недели (0..6) */
    private array $byDay;

    public function __construct(private readonly int $today)
    {
        $this->byDay = [
            1 => new Event('Мафия в беседке',    '19:00',         'Командная игра под гирляндами'),
            2 => new Event('Кино под звёздами',  '18:00 · 20:00', 'Детский и взрослый сеансы'),
            3 => new Event('Live Music',         '19:00',         'Авторская и кавер-программа'),
            4 => new Event('Кино под звёздами',  '18:00 · 20:00', 'Детский и взрослый сеансы'),
            5 => new Event('Live Music',         '19:00',         'BiBi Duo / MRV / TN Band'),
            6 => new Event('Живая музыка',       '19:00',         'The Pennywort, Рядновы и др.'),
            0 => new Event('Вечер живой музыки', '19:00',         'Уютный воскресный вечер'),
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
}
