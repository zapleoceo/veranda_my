<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Reconciliation state of one row — colour-coded in the UI:
 *   RED    unlinked
 *   GREEN  one clean auto-match (auto_green)
 *   YELLOW auto-match with ambiguity (auto_yellow, or any second edge)
 *   GRAY   manually linked
 *   HIDDEN user hid this row via the eye button
 *
 * The classification lives on the server so the template renders the
 * correct CSS class on first paint (no flash of unstyled state).
 */
final class RowState
{
    public const RED    = 'row-red';
    public const GREEN  = 'row-green';
    public const YELLOW = 'row-yellow';
    public const GRAY   = 'row-gray';
    public const HIDDEN = 'row-hidden';

    /**
     * Classify a row given its incident links.
     * @param ReconciliationLink[] $links
     */
    public static function classify(array $links, bool $isHidden = false): string
    {
        if ($isHidden) return self::HIDDEN;
        if ($links === []) return self::RED;

        $hasManual = false;
        $hasYellow = false;
        foreach ($links as $l) {
            if ($l->isManual) $hasManual = true;
            if ($l->linkType === 'auto_yellow') $hasYellow = true;
        }
        if ($hasManual) return self::GRAY;
        return $hasYellow ? self::YELLOW : self::GREEN;
    }
}
