<?php

declare(strict_types=1);

namespace App\Bloggers\Services;

use App\Bloggers\Contracts\BloggerRepositoryInterface;
use App\Bloggers\Contracts\PosterClientsGatewayInterface;
use App\Bloggers\Support\PosterText;

/**
 * Bloggers / referral system — domain orchestration.
 *
 * A blogger is a Poster client in the bloggers group. The promocode is the
 * client's name (set via client_name): a waiter finds it at the POS and
 * attaches the card to a guest's bill, which applies the discount and accrues
 * the sale to that client_id. The report reads dash.getClientsSales for the
 * period — `revenue` is the amount paid after discount (the cashback base) and
 * `checks` is the number of closed checks under the promocode. Cashback % lives
 * in the local repository; everything else (promocode, real name, email,
 * discount) lives on the Poster client.
 *
 * Poster I/O is behind {@see PosterClientsGatewayInterface} and local storage
 * behind {@see BloggerRepositoryInterface}, so this class is pure domain logic
 * and fully unit-testable with fakes.
 */
final class BloggerService
{
    public function __construct(
        private readonly PosterClientsGatewayInterface $poster,
        private readonly BloggerRepositoryInterface $repo,
    ) {}

    // ─── Read ──────────────────────────────────────────────────────────

    /**
     * One row per blogger: Poster card merged with local cashback/active flags.
     * Bloggers added straight in Poster (no local row) still appear (active, 0%).
     *
     * @return list<array<string,mixed>>
     */
    public function listBloggers(): array
    {
        $local = $this->repo->allByClientId();
        $out   = [];
        foreach ($this->poster->listGroupClients() as $c) {
            $id = (int) ($c['client_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $l = $local[$id] ?? null;
            $out[] = [
                'client_id'    => $id,
                'promocode'    => self::promocodeOf($c),
                'name'         => trim((string) ($c['comment'] ?? '')),
                'email'        => trim((string) ($c['email'] ?? '')),
                'discount_pct' => (float) ($c['discount_per'] ?? 0),
                'cashback_pct' => $l['cashback_pct'] ?? 0.0,
                'is_active'    => $l !== null ? (int) $l['is_active'] : 1,
                'total_payed'  => (int) round((float) ($c['total_payed_sum'] ?? 0)),
                'tracked'      => $l !== null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp((string) $a['promocode'], (string) $b['promocode']));
        return $out;
    }

    /**
     * Per-blogger period report (active bloggers only), sorted by revenue desc.
     *
     * @return array{rows: list<array<string,mixed>>, totals: array<string,int>}
     */
    public function report(string $dateFrom, string $dateTo): array
    {
        $sales = $this->poster->clientsSales($dateFrom, $dateTo);

        $rows = [];
        $totChecks = $totRevenue = $totCashback = 0;
        foreach ($this->listBloggers() as $b) {
            if (!$b['is_active']) {
                continue;
            }
            $s        = $sales[$b['client_id']] ?? ['checks' => 0, 'revenue' => 0];
            $checks   = (int) $s['checks'];
            $revenue  = (int) $s['revenue']; // minor units, after discount
            $cashback = self::cashback($revenue, (float) $b['cashback_pct']);

            $rows[] = $b + [
                'checks'   => $checks,
                'revenue'  => $revenue,
                'cashback' => $cashback,
            ];
            $totChecks   += $checks;
            $totRevenue  += $revenue;
            $totCashback += $cashback;
        }
        usort($rows, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return [
            'rows'   => $rows,
            'totals' => [
                'bloggers' => count($rows),
                'checks'   => $totChecks,
                'revenue'  => $totRevenue,
                'cashback' => $totCashback,
            ],
        ];
    }

    // ─── Write ─────────────────────────────────────────────────────────

    /** @throws \RuntimeException on validation / API error. Returns new client_id. */
    public function create(string $promocode, string $name, string $email, float $discountPct, float $cashbackPct, string $createdBy): int
    {
        $promocode = $this->normPromocode($promocode);
        $this->assertPromocodeFree($promocode, null);
        $email = trim($email);

        $clientId = $this->poster->createClient($promocode, $name, $email, $this->normPct($discountPct));
        if ($clientId <= 0) {
            throw new \RuntimeException('Poster не вернул client_id при создании.');
        }
        $this->repo->create($clientId, $email, $this->normPct($cashbackPct), $createdBy);
        return $clientId;
    }

    /** @throws \RuntimeException on validation / API error. */
    public function update(int $clientId, string $promocode, string $name, string $email, float $discountPct, float $cashbackPct): void
    {
        if ($clientId <= 0) {
            throw new \RuntimeException('Не указан блогер.');
        }
        $promocode = $this->normPromocode($promocode);
        $this->assertPromocodeFree($promocode, $clientId);
        $email = trim($email);

        $this->poster->updateClient($clientId, $promocode, $name, $email, $this->normPct($discountPct));
        $this->repo->saveCashbackAndGmail($clientId, $email, $this->normPct($cashbackPct));
    }

    /**
     * Local-only (de)activation: the Poster card and its sales history stay
     * intact; an inactive blogger is just hidden from the report.
     */
    public function setActive(int $clientId, bool $active): void
    {
        if ($clientId <= 0) {
            throw new \RuntimeException('Не указан блогер.');
        }
        $this->repo->setActive($clientId, $active);
    }

    // ─── Domain helpers ────────────────────────────────────────────────

    /** Cashback in minor units = revenue (after discount) × pct%. */
    public static function cashback(int $revenueMinor, float $cashbackPct): int
    {
        return (int) round($revenueMinor * $cashbackPct / 100);
    }

    /**
     * Promocode = the client's displayed name. We always write it via
     * `client_name` as a single token (→ lastname), but reconstruct robustly as
     * "lastname firstname" so a name typed directly in Poster still reads back.
     */
    public static function promocodeOf(array $c): string
    {
        $first = trim((string) ($c['firstname'] ?? ''));
        $last  = trim((string) ($c['lastname'] ?? ''));
        return trim($last . ($first !== '' ? ' ' . $first : ''));
    }

    private function normPct(float $p): float
    {
        return max(0.0, min(100.0, round($p, 2)));
    }

    /** Trimmed, emoji-stripped, no whitespace (typeable at the POS), ≤ 50 chars. */
    private function normPromocode(string $p): string
    {
        $p = PosterText::safe($p);
        if ($p === '') {
            throw new \RuntimeException('Промокод не может быть пустым.');
        }
        if (preg_match('/\s/u', $p)) {
            throw new \RuntimeException('Промокод не должен содержать пробелов (его ищут на кассе).');
        }
        if (mb_strlen($p) > 50) {
            throw new \RuntimeException('Промокод слишком длинный (макс. 50 символов).');
        }
        return $p;
    }

    /** No other blogger may share the promocode (case-insensitive). */
    private function assertPromocodeFree(string $promocode, ?int $exceptClientId): void
    {
        $needle = mb_strtolower($promocode);
        foreach ($this->poster->listGroupClients() as $c) {
            $id = (int) ($c['client_id'] ?? 0);
            if ($exceptClientId !== null && $id === $exceptClientId) {
                continue;
            }
            if (mb_strtolower(self::promocodeOf($c)) === $needle) {
                throw new \RuntimeException("Промокод «{$promocode}» уже занят другим блогером.");
            }
        }
    }
}
