<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * VND amount stored as integer minor units (1 unit = 1 VND).
 *
 * Encapsulates the conversion + formatting logic that lives across
 * payday2/FinanceHelper.php and inline `Intl.NumberFormat` calls in the
 * 11 JavaScript files.
 *
 * Poster's API returns cents (1 minor unit = 0.01 VND). Convert with
 * Money::fromPosterCents().
 */
final class Money
{
    private function __construct(public readonly int $amount) {}

    public static function vnd(int $amount): self { return new self($amount); }

    public static function fromPosterCents(int $cents): self
    {
        if ($cents === 0) return new self(0);
        if ($cents % 100 === 0) return new self((int)($cents / 100));
        return new self((int)round($cents / 100));
    }

    /**
     * Poster API consistently returns monetary fields in cents (1 cent =
     * 0.01 VND), but the wire format is sometimes int, sometimes string
     * (e.g. "1500000.00"). This is the lenient companion to
     * fromPosterCents(): accepts any scalar and returns the VND
     * integer directly (skipping the Money wrapper) so callers can
     * splat the result straight into JSON response shapes.
     */
    public static function posterMinorToVnd(mixed $raw): int
    {
        if ($raw === null || $raw === '') return 0;
        if (is_int($raw))   return self::fromPosterCents($raw)->amount;
        if (is_float($raw)) return self::fromPosterCents((int)round($raw))->amount;
        if (is_string($raw)) {
            $t = self::normaliseNumeric($raw);
            if ($t === null) return 0;
            return self::fromPosterCents((int)round((float)$t))->amount;
        }
        return 0;
    }

    /**
     * Lenient integer for any wire value that is ALREADY in the target unit
     * (Poster dash.* raw minor units kept as-is, SePay VND, Poster v3
     * "35000.00" VND strings). Non-scalars / garbage → 0.
     *
     * Replaces the private moneyToInt()/vndFromV3() copies that lived in
     * PosterSyncService, SepaySyncService, FinanceTransferService and
     * PosterCheckService.
     */
    public static function toInt(mixed $raw): int
    {
        if (is_int($raw) || is_float($raw) || is_string($raw) || $raw === null) {
            return self::parse($raw)->amount;
        }
        return 0;
    }

    /** Lenient parser for user input / DB strings. */
    public static function parse(int|float|string|null $v): self
    {
        if (is_int($v))   return new self($v);
        if (is_float($v)) return new self((int)round($v));
        if (is_string($v)) {
            $t = self::normaliseNumeric($v);
            return new self($t === null ? 0 : (int)round((float)$t));
        }
        return new self(0);
    }

    /** "1 234,50" → "1234.50"; null when not numeric. */
    private static function normaliseNumeric(string $s): ?string
    {
        $t = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim($s));
        return ($t !== '' && is_numeric($t)) ? $t : null;
    }

    public function plus(self $other): self { return new self($this->amount + $other->amount); }
    public function minus(self $other): self { return new self($this->amount - $other->amount); }
    public function isZero(): bool { return $this->amount === 0; }
    public function isNegative(): bool { return $this->amount < 0; }

    /** Display VND with narrow-no-break-space thousand separator (matches payday2 UI). */
    public function format(): string
    {
        $neg = $this->amount < 0;
        $abs = $neg ? -$this->amount : $this->amount;
        $intFmt = number_format($abs, 0, '.', "\u{202F}");
        return ($neg && $abs > 0 ? '-' : '') . $intFmt;
    }
}
