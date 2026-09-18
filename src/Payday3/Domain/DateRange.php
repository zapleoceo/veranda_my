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

    /** Reads / syncs / check search: at most a month per request. */
    public const MAX_READ_DAYS = 31;
    /** Destructive endpoints (clear day / clear links): one day only. */
    public const MAX_DESTRUCTIVE_DAYS = 1;

    /** fromQuery() + the read-span guard (heavy endpoints). */
    public static function forRead(array $query): self
    {
        return self::fromQuery($query)->limitedTo(self::MAX_READ_DAYS);
    }

    /** fromQuery() + the single-day guard (clear endpoints). */
    public static function forDestructive(array $query): self
    {
        return self::fromQuery($query)->limitedTo(self::MAX_DESTRUCTIVE_DAYS);
    }

    /** Inclusive number of calendar days (a single day = 1). */
    public function days(): int
    {
        $a = new \DateTimeImmutable($this->from);
        $b = new \DateTimeImmutable($this->to);
        return (int)$a->diff($b)->days + 1;
    }

    /**
     * Span guard. A dateFrom=2000-01-01&dateTo=2099-12-31 request would
     * otherwise wipe every link (clear) or page through Poster for
     * minutes (checks/find).
     *
     * @throws \InvalidArgumentException (→ 400 via JsonResponder)
     */
    public function limitedTo(int $maxDays): self
    {
        if ($this->days() > $maxDays) {
            throw new \InvalidArgumentException($maxDays === 1
                ? 'Эта операция работает только с одним днём: выберите dateFrom = dateTo.'
                : sprintf('Слишком большой период: максимум %d дн. (запрошено %d).', $maxDays, $this->days()));
        }
        return $this;
    }

    public function isSingleDay(): bool { return $this->from === $this->to; }

    public function asArray(): array { return ['from' => $this->from, 'to' => $this->to]; }

    private static function valid(string $s): bool
    {
        return $s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1;
    }
}
