<?php

declare(strict_types=1);

namespace App\Bloggers\Services;

use App\Bloggers\Contracts\BloggerRepositoryInterface;
use App\Bloggers\Contracts\PosterClientsGatewayInterface;
use App\Bloggers\Support\BloggerMeta;
use App\Bloggers\Support\PosterText;

/**
 * Bloggers / referral system — domain orchestration.
 *
 * A blogger is a Poster client in the configured group. The promocode is the
 * client's name (client_name); a waiter attaches the card at the POS, applying
 * the discount and accruing the sale to that client_id. Cashback = revenue
 * after discount × cashback%.
 *
 * Parameter storage: discount lives in Poster's native `discount_per` (the POS
 * applies it). Everything else — display name, cashback %, per-blogger limit %,
 * social links — is packed into the Poster client `comment` via BloggerMeta.
 * The local `bloggers` table holds only the approval flag + creator.
 *
 * Limit rule: discount % + cashback % ≤ the blogger's limit % (default 15;
 * self-registration starts at 5; the manager sets it per blogger).
 *
 * Payouts mirror /employees: a payout is a Poster expense finance transaction
 * in the configured category, tagged `... ID=<client_id> ...` in the comment;
 * "paid" is read back from finance.getTransactions of that category and matched
 * by the ID tag. "К выплате" = accrued cashback − already paid (≥ 0).
 */
final class BloggerService
{
    /** Default limit % assigned to a self-registered blogger (manager raises it). */
    public const REGISTER_LIMIT = 5.0;

    private ?array $cfgCache     = null;
    private ?array $clientsCache = null;

    public function __construct(
        private readonly PosterClientsGatewayInterface $poster,
        private readonly BloggerRepositoryInterface $repo,
    ) {}

    /**
     * Request-scoped cache of the Poster blogger group. listBloggers / report /
     * posterRow / assertPromocodeFree all read it, so one save previously did
     * several getClients(num:1000) scans — now a single fetch per request,
     * invalidated after any client mutation.
     *
     * @return list<array<string,mixed>>
     */
    private function groupClients(): array
    {
        return $this->clientsCache ??= $this->poster->listGroupClients($this->cfg()['group_id']);
    }

    private function invalidateClients(): void
    {
        $this->clientsCache = null;
    }

    // ─── Read ──────────────────────────────────────────────────────────

    /**
     * One row per blogger: Poster card decoded (name/cashback/limit/socials)
     * merged with the local active flag.
     *
     * @return list<array<string,mixed>>
     */
    public function listBloggers(): array
    {
        $local = $this->repo->allByClientId();
        $out   = [];
        foreach ($this->groupClients() as $c) {
            $id = (int) ($c['client_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $l    = $local[$id] ?? null;
            $meta = BloggerMeta::decode((string) ($c['comment'] ?? ''));
            // Cashback: comment is authoritative; fall back to the legacy local
            // column for rows created before the comment held it.
            $cashback = $meta->cashbackOr((float) ($l['cashback_pct'] ?? 0.0));
            $out[] = [
                'client_id'    => $id,
                'promocode'    => self::promocodeOf($c),
                'name'         => $meta->name,
                'socials'      => $meta->socials,
                'email'        => trim((string) ($c['email'] ?? '')),
                'discount_pct' => (float) ($c['discount_per'] ?? 0),
                'cashback_pct' => $cashback,
                'limit_pct'    => $meta->limitPct,
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
     * Pass $onlyClientId to scope the report to a single blogger (the mobile
     * cabinet) — then only that row is returned and the totals cover just them.
     *
     * @return array{rows: list<array<string,mixed>>, totals: array<string,int>}
     */
    public function report(string $dateFrom, string $dateTo, ?int $onlyClientId = null): array
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
            if ($onlyClientId !== null && $b['client_id'] !== $onlyClientId) {
                continue;
            }
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

    /**
     * Find a blogger's Poster client_id by their email address (used during
     * Google OAuth). Matches against the email on the Poster client record
     * (case-insensitive). Returns 0 if not found or not active.
     */
    public function findByEmail(string $email): int
    {
        if ($email === '') {
            return 0;
        }
        $needle = strtolower(trim($email));
        $local  = $this->repo->allByClientId();
        try {
            foreach ($this->groupClients() as $c) {
                $clientEmail = strtolower(trim((string) ($c['email'] ?? '')));
                if ($clientEmail === '' || $clientEmail !== $needle) {
                    continue;
                }
                $id = (int) ($c['client_id'] ?? 0);
                if ($id > 0 && isset($local[$id]) && $local[$id]['is_active']) {
                    return $id;
                }
            }
        } catch (\Throwable) {
            // Poster API unavailable — fail closed
        }
        return 0;
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

    /**
     * Manager creates a blogger. discount+cashback ≤ limit enforced.
     *
     * @param  array<string,string> $socials
     * @throws \RuntimeException Returns new client_id.
     */
    public function create(string $promocode, string $name, string $email, float $discountPct, float $cashbackPct, float $limitPct, array $socials, string $createdBy): int
    {
        $promocode = $this->normPromocode($promocode);
        $this->assertPromocodeFree($promocode, null);
        $email = trim($email);

        $d   = $this->normPct($discountPct);
        $c   = $this->normPct($cashbackPct);
        $lim = $this->normLimit($limitPct);
        $this->assertWithinLimit($d, $c, $lim);

        $meta = new BloggerMeta(
            name: trim($name),
            cashbackPct: $c,
            limitPct: $lim,
            socials: $this->normSocials($socials),
        );

        $clientId = $this->poster->createClient($this->cfg()['group_id'], $promocode, $meta->encode(), $email, $d);
        $this->invalidateClients();
        if ($clientId <= 0) {
            throw new \RuntimeException('Poster не вернул client_id при создании.');
        }
        $this->repo->create($clientId, $createdBy);
        return $clientId;
    }

    /**
     * Manager edits a blogger (all params). discount+cashback ≤ limit enforced.
     *
     * @param  array<string,string> $socials
     * @throws \RuntimeException
     */
    public function update(int $clientId, string $promocode, string $name, string $email, float $discountPct, float $cashbackPct, float $limitPct, array $socials): void
    {
        if ($clientId <= 0) {
            throw new \RuntimeException('Не указан блогер.');
        }
        $promocode = $this->normPromocode($promocode);
        $this->assertPromocodeFree($promocode, $clientId);
        $email = trim($email);

        $d   = $this->normPct($discountPct);
        $c   = $this->normPct($cashbackPct);
        $lim = $this->normLimit($limitPct);
        $this->assertWithinLimit($d, $c, $lim);

        $meta = new BloggerMeta(
            name: trim($name),
            cashbackPct: $c,
            limitPct: $lim,
            socials: $this->normSocials($socials),
        );

        $this->poster->updateClient($this->cfg()['group_id'], $clientId, $promocode, $meta->encode(), $email, $d);
        $this->invalidateClients();
    }

    /**
     * Self-registration: anyone may sign up and start using their promocode
     * immediately — the safety is the low REGISTER_LIMIT (5%) cap on
     * discount+cashback, not a manual approval gate. To raise the limit the
     * blogger must contact the owner, who bumps it in /admin/bloggers. Socials
     * go into the comment. (A manager can still deactivate a bad actor.)
     *
     * @param  array<string,string> $socials
     * @throws \RuntimeException
     */
    public function register(string $promocode, string $name, string $email, array $socials): int
    {
        $promocode = $this->normPromocode($promocode);
        $this->assertPromocodeFree($promocode, null);

        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Укажите корректный email.');
        }
        $name = trim(PosterText::safe($name));
        if ($name === '') {
            throw new \RuntimeException('Укажите ваше имя.');
        }

        foreach ($this->groupClients() as $c) {
            if (strtolower(trim((string) ($c['email'] ?? ''))) === $email) {
                throw new \RuntimeException('Этот email уже зарегистрирован в программе.');
            }
        }

        $meta = new BloggerMeta(
            name: $name,
            cashbackPct: 0.0,
            limitPct: self::REGISTER_LIMIT,
            socials: $this->normSocials($socials),
        );

        $clientId = $this->poster->createClient($this->cfg()['group_id'], $promocode, $meta->encode(), $email, 0.0);
        $this->invalidateClients();
        if ($clientId <= 0) {
            throw new \RuntimeException('Ошибка при создании аккаунта. Попробуйте позже.');
        }

        // Active immediately at the 5% cap — the blogger can log in and use
        // their promocode right away (manager raises the limit later).
        $this->repo->create($clientId, 'self');

        return $clientId;
    }

    /**
     * Blogger edits their own promocode, discount %, and cashback %.
     * Name, limit and socials are preserved from the existing comment.
     *
     * Rule: discount % + cashback % ≤ the blogger's own limit %.
     *
     * @throws \RuntimeException
     */
    public function selfUpdate(int $clientId, string $promocode, float $discountPct, float $cashbackPct): void
    {
        if ($clientId <= 0) {
            throw new \RuntimeException('Не указан блогер.');
        }
        $row = $this->posterRow($clientId);
        if ($row === null) {
            throw new \RuntimeException('Блогер не найден в системе.');
        }
        $meta = BloggerMeta::decode((string) ($row['comment'] ?? ''));

        $d = $this->normPct($discountPct);
        $c = $this->normPct($cashbackPct);
        $this->assertWithinLimit($d, $c, $meta->limitPct);

        $promocode = $this->normPromocode($promocode);
        $this->assertPromocodeFree($promocode, $clientId);

        // Preserve name / limit / socials; update only the cashback.
        $meta->cashbackPct = $c;

        $this->poster->updateClient(
            $this->cfg()['group_id'],
            $clientId,
            $promocode,
            $meta->encode(),
            (string) ($row['email'] ?? ''),
            $d,
        );
        $this->invalidateClients();
    }

    /** Individual closed checks for the period, filtered to one client. */
    public function checks(string $dateFrom, string $dateTo, int $clientId): array
    {
        return $this->poster->clientChecks($dateFrom, $dateTo, $clientId);
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
     * The manager may pass a custom $comment, but the `ID=<client_id>` tag —
     * which ties the payout to the blogger for paid-matching — is always
     * enforced. @throws \RuntimeException Returns the created transaction id.
     */
    public function pay(int $clientId, int $amountVnd, int $accountId, string $by, string $comment = ''): int
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

        $comment = trim($comment);
        if ($comment === '') {
            $comment = 'BLOGGER ' . ($promo !== '' ? $promo . ' ' : '') . 'ID=' . $clientId . ($by !== '' ? ' by ' . $by : '');
        } elseif (!preg_match('/\bID=' . $clientId . '\b/', $comment)) {
            $comment .= ' ID=' . $clientId;
        }

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

    /** Raw Poster client row in the blogger group by id, or null. */
    private function posterRow(int $clientId): ?array
    {
        foreach ($this->groupClients() as $c) {
            if ((int) ($c['client_id'] ?? 0) === $clientId) {
                return $c;
            }
        }
        return null;
    }

    /** @param array<string,string> $s @return array<string,string> whitelisted, trimmed */
    private function normSocials(array $s): array
    {
        $out = [];
        foreach (BloggerMeta::SOCIAL_KEYS as $k) {
            $out[$k] = trim((string) ($s[$k] ?? ''));
        }
        return $out;
    }

    private function normPct(float $p): float
    {
        return max(0.0, min(100.0, round($p, 2)));
    }

    /** Limit clamped to (0,100]; a non-positive value falls back to the default. */
    private function normLimit(float $p): float
    {
        $p = $this->normPct($p);
        return $p > 0 ? $p : BloggerMeta::DEFAULT_LIMIT;
    }

    private function assertWithinLimit(float $discount, float $cashback, float $limit): void
    {
        if ($discount + $cashback > $limit + 0.0001) {
            throw new \RuntimeException(sprintf(
                'Скидка (%s%%) + кешбек (%s%%) превышают лимит %s%%.',
                self::pctStr($discount),
                self::pctStr($cashback),
                self::pctStr($limit),
            ));
        }
    }

    private static function pctStr(float $p): string
    {
        $s = rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
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
        foreach ($this->groupClients() as $c) {
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
