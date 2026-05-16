<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * Immutable date range with strict validation.
 *
 * Replaces the manual date-parsing scattered across payday2/index.php,
 * payday2/ajax.php and payday2/view.php (every action re-parses the same
 * $_GET['dateFrom'] / $_GET['dateTo'] / $_GET['date'] block).
 */
final class DateRange
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {}

    /** @throws \InvalidArgumentException when either date is malformed. */
    public static function of(string $from, string $to): self
    {
        if (!self::valid($from) || !self::valid($to)) {
            throw new \InvalidArgumentException('DateRange: expected YYYY-MM-DD');
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        return new self($from, $to);
    }

    /**
     * Lenient factory used by HTTP request parsing — accepts the legacy
     * payday2 input shape (dateFrom/dateTo/date) and falls back to today.
     */
    public static function fromQuery(array $query): self
    {
        $from = trim((string)($query['dateFrom'] ?? ''));
        $to   = trim((string)($query['dateTo']   ?? ''));
        $one  = trim((string)($query['date']     ?? ''));

        if ($from === '' && $to === '' && $one !== '') {
            $from = $one;
            $to   = $one;
        }
        if ($from === '' && $to !== '') $from = $to;
        if ($to === '' && $from !== '') $to = $from;

        if (!self::valid($from)) $from = date('Y-m-d');
        if (!self::valid($to))   $to   = $from;

        return self::of($from, $to);
    }

    public function isSingleDay(): bool { return $this->from === $this->to; }

    public function asArray(): array { return ['from' => $this->from, 'to' => $this->to]; }

    private static function valid(string $s): bool
    {
        return $s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
    }
}
