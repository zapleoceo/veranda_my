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
            'templates' => [
                ['name' => 'Д',      'start' => '09:00', 'end' => '17:00'],
                ['name' => 'В',      'start' => '16:00', 'end' => '23:00'],
                ['name' => 'У',      'start' => '09:00', 'end' => '14:00'],
                ['name' => 'Полный', 'start' => '09:00', 'end' => '23:00'],
            ],
        ];
    }
}
