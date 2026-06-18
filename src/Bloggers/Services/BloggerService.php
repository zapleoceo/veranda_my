<?php

declare(strict_types=1);

namespace App\Bloggers\Services;

use App\Bloggers\Contracts\BloggerRepositoryInterface;
use App\Bloggers\Contracts\PosterClientsGatewayInterface;
use App\Bloggers\Support\PosterText;

/**
 * Bloggers / referral system — domain orchestration.
 *
 * A blogger is a Poster client in the configured group. The promocode is the
 * client's name (client_name); a waiter attaches the card at the POS, applying
 * the discount and accruing the sale to that client_id. Cashback = revenue
 * after discount × cashback%.
 *
 * Payouts mirror /employees: a payout is a Poster expense finance transaction
 * in the configured category, tagged `... ID=<client_id> ...` in the comment;
 * "paid" is read back from finance.getTransactions of that category and matched
 * by the ID tag. "К выплате" = accrued cashback − already paid (≥ 0). The
 * search window for paid transactions runs from the period start through today,
 * so a payout made now for an earlier period is still found.
 */
final class BloggerService
{
    private ?array $cfgCache = null;

    public function __construct(
        private readonly PosterClientsGatewayInterface $poster,
        private readonly BloggerRepositoryInterface $repo,
    ) {}

    // ─── Read ──────────────────────────────────────────────────────────

    /**
     * One row per blogger: Poster card merged with local cashback/active flags.
     *
     * @return list<array<string,mixed>>
     */
    public function listBloggers(): array
    {
        $local = $this->repo->allByClientId();
        $out   = [];
        foreach ($this->poster->listGroupClients($this->cfg()['group_id']) as $c) {
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
     * Per-blogger period report. Every blogger is listed (active first, then by
     * amount-to-pay desc). Each row carries accrued cashback, already-paid and
     * to-pay (all minor units). Totals are the payout figures over active
     * bloggers only.
     *
     * @return array{rows: list<array<string,mixed>>, totals: array<string,int>}
     */
    public function report(string $dateFrom, string $dateTo): array
    {
        $cfg    = $this->cfg();
        $sales  = $this->poster->clientsSales($dateFrom, $dateTo);
        // Look for payouts from the period start through today, so a payment
        // made now for an earlier period is still matched.
        $paidTo = $dateTo < date('Y-m-d') ? date('Y-m-d') : $dateTo;
        $paid   = $this->poster->payouts($cfg['payout_category_id'], $dateFrom, $paidTo);

        $rows = [];
        $activeCount = $totChecks = $totRevenue = $totCashback = $totPaid = $totToPay = 0;
        foreach ($this->listBloggers() as $b) {
            $s         = $sales[$b['client_id']] ?? ['checks' => 0, 'revenue' => 0];
            $checks    = (int) $s['checks'];
            $revenue   = (int) $s['revenue'];
            $cashback  = self::cashback($revenue, (float) $b['cashback_pct']);
            $paidMinor = (int) ($paid[$b['client_id']] ?? 0);
            $toPay     = max(0, $cashback - $paidMinor);

            $rows[] = $b + [
                'checks'   => $checks,
                'revenue'  => $revenue,
                'cashback' => $cashback,
                'paid'     => $paidMinor,
                'topay'    => $toPay,
            ];
            if ($b['is_active']) {
                $activeCount++;
                $totChecks   += $checks;
                $totRevenue  += $revenue;
                $totCashback += $cashback;
                $totPaid     += $paidMinor;
                $totToPay    += $toPay;
            }
        }
        usort($rows, static fn (array $a, array $b): int =>
            ($b['is_active'] <=> $a['is_active']) ?: ($b['topay'] <=> $a['topay']));

        return [
            'rows'   => $rows,
            'totals' => [
                'bloggers' => $activeCount,
                'checks'   => $totChecks,
                'revenue'  => $totRevenue,
                'cashback' => $totCashback,
                'paid'     => $totPaid,
                'topay'    => $totToPay,
            ],
        ];
    }

    /** Finance accounts for the payout dropdown. @return array<int,string> */
    public function accounts(): array
    {
        return $this->poster->financeAccounts();
    }

    /** @return array{group_id:int,payout_category_id:int} */
    public function config(): array
    {
        return $this->cfg();
    }

    // ─── Write ─────────────────────────────────────────────────────────

    /** @throws \RuntimeException. Returns new client_id. */
    public function create(string $promocode, string $name, string $email, float $discountPct, float $cashbackPct, string $createdBy): int
    {
        $promocode = $this->normPromocode($promocode);
        $this->assertPromocodeFree($promocode, null);
        $email = trim($email);

        $clientId = $this->poster->createClient($this->cfg()['group_id'], $promocode, $name, $email, $this->normPct($discountPct));
        if ($clientId <= 0) {
            throw new \RuntimeException('Poster не вернул client_id при создании.');
        }
        $this->repo->create($clientId, $email, $this->normPct($cashbackPct), $createdBy);
        return $clientId;
    }

    /** @throws \RuntimeException */
    public function update(int $clientId, string $promocode, string $name, string $email, float $discountPct, float $cashbackPct): void
    {
        if ($clientId <= 0) {
            throw new \RuntimeException('Не указан блогер.');
        }
        $promocode = $this->normPromocode($promocode);
        $this->assertPromocodeFree($promocode, $clientId);
        $email = trim($email);

        $this->poster->updateClient($this->cfg()['group_id'], $clientId, $promocode, $name, $email, $this->normPct($discountPct));
        $this->repo->saveCashbackAndGmail($clientId, $email, $this->normPct($cashbackPct));
    }

    public function setActive(int $clientId, bool $active): void
    {
        if ($clientId <= 0) {
            throw new \RuntimeException('Не указан блогер.');
        }
        $this->repo->setActive($clientId, $active);
    }

    /**
     * Pay cashback to a blogger — records a Poster expense transaction in the
     * payout category tagged with the blogger's client_id. $amountVnd is in VND.
     *
     * @throws \RuntimeException Returns the created transaction id.
     */
    public function pay(int $clientId, int $amountVnd, int $accountId, string $by): int
    {
        if ($clientId <= 0) {
            throw new \RuntimeException('Не указан блогер.');
        }
        if ($amountVnd <= 0) {
            throw new \RuntimeException('Сумма выплаты должна быть больше нуля.');
        }
        if ($accountId <= 0) {
            throw new \RuntimeException('Не выбран счёт выплаты.');
        }

        $promo = '';
        foreach ($this->listBloggers() as $b) {
            if ($b['client_id'] === $clientId) {
                $promo = (string) $b['promocode'];
                break;
            }
        }
        $comment = 'BLOGGER ' . ($promo !== '' ? $promo . ' ' : '') . 'ID=' . $clientId . ($by !== '' ? ' by ' . $by : '');

        return $this->poster->createPayout($this->cfg()['payout_category_id'], $accountId, $amountVnd, $comment);
    }

    /** @throws \RuntimeException Persist the module config. */
    public function saveConfig(int $groupId, int $payoutCategoryId): void
    {
        if ($groupId <= 0 || $payoutCategoryId <= 0) {
            throw new \RuntimeException('ID группы и категории должны быть положительными числами.');
        }
        $this->repo->saveConfig($groupId, $payoutCategoryId);
        $this->cfgCache = ['group_id' => $groupId, 'payout_category_id' => $payoutCategoryId];
    }

    // ─── Domain helpers ────────────────────────────────────────────────

    /** Cashback in minor units = revenue (after discount) × pct%. */
    public static function cashback(int $revenueMinor, float $cashbackPct): int
    {
        return (int) round($revenueMinor * $cashbackPct / 100);
    }

    /**
     * Promocode = the client's displayed name; reconstructed as
     * "lastname firstname" (we write single-token promocodes via client_name).
     */
    public static function promocodeOf(array $c): string
    {
        $first = trim((string) ($c['firstname'] ?? ''));
        $last  = trim((string) ($c['lastname'] ?? ''));
        return trim($last . ($first !== '' ? ' ' . $first : ''));
    }

    /** @return array{group_id:int,payout_category_id:int} */
    private function cfg(): array
    {
        return $this->cfgCache ??= $this->repo->loadConfig();
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
        foreach ($this->poster->listGroupClients($this->cfg()['group_id']) as $c) {
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
