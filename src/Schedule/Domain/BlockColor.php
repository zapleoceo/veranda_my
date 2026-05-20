<?php

declare(strict_types=1);

namespace App\Schedule\Domain;

/**
 * Maps a block to its visual color class. Pure logic, no I/O.
 *
 * State block carries an explicit `color` field; this helper falls back to
 * heuristics for older snapshots that didn't have it.
 */
final class BlockColor
{
    public const SENIOR = 'senior';
    public const MAIN   = 'main';
    public const BANYA  = 'banya';
    public const CUSTOM = 'custom';

    public const ALL = [self::SENIOR, self::MAIN, self::BANYA, self::CUSTOM];

    public static function of(array $block): string
    {
        $c = $block['color'] ?? '';
        if (in_array($c, self::ALL, true)) return $c;
        if (($block['type'] ?? '') === 'senior') return self::SENIOR;
        if (($block['type'] ?? '') === 'custom') return self::CUSTOM;
        // Heuristic: hall:2 is banya by convention (Veranda)
        if (($block['id'] ?? '') === 'hall:2') return self::BANYA;
        return self::MAIN;
    }

    /** CSS class for the block header. */
    public static function headerClass(string $color): string
    {
        return match ($color) {
            self::SENIOR => 'senior',
            self::BANYA  => 'hall-banya',
            self::CUSTOM => 'hall-custom',
            default      => 'hall-main',
        };
    }

    /** CSS class for the divider column. */
    public static function dividerClass(string $color): string
    {
        return $color === self::MAIN ? 'main' : $color;
    }
}
