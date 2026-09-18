<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Tenant-wide Poster ids that are NOT operator-tunable (payment methods,
 * fixed category, tablet). Single source — before this they were
 * re-declared as private consts / template `const`s in five places, and
 * InDataAction guessed VC/BB from the method NAME.
 *
 * Account ids that operators may change live in LocalSettings instead.
 */
final class PosterIds
{
    /** poster_payment_method_id «Vietnam Company». */
    public const METHOD_VIETNAM = 11;
    /** poster_payment_method_id «Bybit». */
    public const METHOD_BYBIT   = 12;

    /** Finance category used for the UPLD balance correction. */
    public const CATEGORY_BALANCE_CORRECTION = 4;

    /** spot_tablet_id sent with transactions.removeTransaction. */
    public const SPOT_TABLET_ID = 1;

    /** Short label for a payment method id (VC / BB) or null. */
    public static function methodLite(?int $methodId): ?string
    {
        return match ($methodId) {
            self::METHOD_VIETNAM => 'VC',
            self::METHOD_BYBIT   => 'BB',
            default              => null,
        };
    }
}
