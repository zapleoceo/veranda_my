<?php

declare(strict_types=1);

namespace App\Schedule\Domain;

/**
 * Factory for the "first visit" state — block structure with empty shifts.
 * Pure, deterministic.
 */
final class DefaultState
{
    public static function make(): array
    {
        return [
            'version' => 1,
            'blocks'  => [
                [
                    'id'    => 'senior',
                    'type'  => 'senior',
                    'color' => BlockColor::SENIOR,
                    'name'  => 'Старшие смены',
                    'icon'  => '⭐',
                    'slots' => [
                        ['label' => 'день',  'defaultTime' => '09:00-17:00'],
                        ['label' => 'день',  'defaultTime' => '09:00-17:00'],
                        ['label' => 'вечер', 'defaultTime' => '16:00-23:00'],
                    ],
                ],
                [
                    'id'      => 'hall:1',
                    'type'    => 'hall',
                    'hall_id' => 1,
                    'color'   => BlockColor::MAIN,
                    'name'    => 'Главный зал',
                    'icon'    => '🏛',
                    'slots'   => [
                        ['label' => 'утро',  'defaultTime' => '09:00-17:00'],
                        ['label' => 'утро',  'defaultTime' => '09:00-17:00'],
                        ['label' => 'вечер', 'defaultTime' => '16:00-23:00'],
                        ['label' => 'вечер', 'defaultTime' => '16:00-23:00'],
                    ],
                ],
                [
                    'id'      => 'hall:2',
                    'type'    => 'hall',
                    'hall_id' => 2,
                    'color'   => BlockColor::BANYA,
                    'name'    => 'Баня',
                    'icon'    => '♨',
                    'slots'   => [
                        ['label' => 'весь день', 'defaultTime' => '10:00-18:00'],
                    ],
                ],
                [
                    'id'      => 'zone:1',
                    'type'    => 'custom',
                    'zone_id' => 1,
                    'color'   => BlockColor::CUSTOM,
                    'name'    => 'Беседка',
                    'icon'    => '🌿',
                    'slots'   => [
                        ['label' => 'по брони', 'defaultTime' => '18:00-23:00'],
                    ],
                ],
            ],
            'shifts'    => new \stdClass(),
            'templates' => [],
            // Rule engine — evaluated in JS, persisted with the state.
            // Three system rules (cannot be deleted, only toggled) +
            // two user rules seeded with sensible Veranda defaults.
            'rules' => [
                ['id' => 'sys-senior', 'type' => 'needSenior',    'scope' => 'all',  'enabled' => true, 'name' => 'Нет старшего смены',        'system' => true],
                ['id' => 'sys-double', 'type' => 'doubleBooking', 'scope' => 'all',  'enabled' => true, 'name' => 'Двойное бронирование',       'system' => true],
                ['id' => 'sys-roster', 'type' => 'offRoster',     'scope' => 'all',  'enabled' => true, 'name' => 'Назначен «не в графике»',    'system' => true],
                ['id' => 'r-start-main', 'type' => 'startTime', 'scope' => 'main', 'enabled' => true, 'value' => '10:00',                       'name' => 'Главный зал: старт в 10:00'],
                ['id' => 'r-end-main',   'type' => 'endTime',   'scope' => 'main', 'enabled' => true, 'value' => '22:00', 'weekendValue' => '23:00', 'name' => 'Главный зал: конец в 22:00 (23:00 Пт/Сб/Вс)'],
            ],
        ];
    }
}
