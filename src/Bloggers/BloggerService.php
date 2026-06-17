<?php

declare(strict_types=1);

namespace App\Bloggers;

use App\Infrastructure\Database;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Bloggers / referral system.
 *
 * A "blogger" is a Poster client in group {@see GROUP_ID}. The model:
 *   - client_name (Poster) = promocode   — the waiter searches this at the POS
 *                                          and attaches the card to a guest's
 *                                          bill, which applies the discount and
 *                                          accrues the sale to this client_id.
 *   - comment     (Poster) = real name of the blogger.
 *   - email       (Poster) = gmail (used by the blogger cabinet login, phase 2).
 *   - discount_per(Poster) = personal discount %, auto-applied when attached.
 * Cashback % lives only here (Poster has no field for it) in the local
 * `bloggers` table, linked by poster_client_id.
 *
 * Reporting reads dash.getClientsSales for the period: `revenue` is the amount
 * actually paid after discount (= the cashback base) and `clients` is the
 * number of closed checks under the promocode. Verified live against client 85.
 *
 * Quirks of Poster's clients.* API (probed live, see commit message):
 *   - name is set via the single `client_name` param ("Фамилия Имя" order; a
 *     single token lands in lastname). `firstname`/`lastname` params are ignored.
 *   - real name is set via `comment`; group via `client_groups_id_client`.
 *   - 4-byte UTF-8 (emoji) nulls the whole field — strip it (utf8mb3 db).
 */
final class BloggerService
{
    /** Poster client group that holds every blogger. */
    public const GROUP_ID = 10;

    private static bool $schemaReady = false;

    public function __construct(
        private readonly PosterApiProviderInterface $poster,
        private readonly Database $db,
    ) {
        $this->ensureSchema();
    }

    // ─── Read ──────────────────────────────────────────────────────────

    /**
     * One row per blogger: Poster card merged with the local cashback/active
     * flags. Bloggers added straight in Poster (no local row) still show up.
     *
     * @return list<array<string,mixed>>
     */
    public function listBloggers(): array
    {
        $local = $this->localByClientId();
        $out   = [];
        foreach ($this->posterClients() as $c) {
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
        $sales = $this->clientsSales($dateFrom, $dateTo);

        $rows = [];
        $totChecks = $totRevenue = $totCashback = 0;
        foreach ($this->listBloggers() as $b) {
            if (!$b['is_active']) {
                continue;
            }
            $s        = $sales[$b['client_id']] ?? ['checks' => 0, 'revenue' => 0];
            $checks   = (int) $s['checks'];
            $revenue  = (int) $s['revenue']; // minor units, after discount
            $cashback = (int) round($revenue * (float) $b['cashback_pct'] / 100);

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

        $resp = $this->poster->client()->request('clients.createClient', [
            'client_name'             => $promocode,
            'client_groups_id_client' => self::GROUP_ID,
            'discount_per'            => $this->normPct($discountPct),
            'email'                   => $email,
            'comment'                 => self::posterSafe($name),
        ], 'POST');

        $clientId = (int) (is_array($resp) ? ($resp[0] ?? 0) : $resp);
        if ($clientId <= 0) {
            throw new \RuntimeException('Poster не вернул client_id при создании.');
        }

        $this->db->query(
            "INSERT INTO {$this->db->t('bloggers')} (poster_client_id, gmail, cashback_pct, is_active, created_by)
             VALUES (?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE gmail = VALUES(gmail), cashback_pct = VALUES(cashback_pct), is_active = 1",
            [$clientId, $email, $this->normPct($cashbackPct), $createdBy]
        );
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

        $this->poster->client()->request('clients.updateClient', [
            'client_id'               => $clientId,
            'client_name'             => $promocode,
            'client_groups_id_client' => self::GROUP_ID,
            'discount_per'            => $this->normPct($discountPct),
            'email'                   => $email,
            'comment'                 => self::posterSafe($name),
        ], 'POST');

        // Local row may not exist yet if the blogger was created directly in
        // Poster — upsert keeps cashback/gmail in sync either way.
        $this->db->query(
            "INSERT INTO {$this->db->t('bloggers')} (poster_client_id, gmail, cashback_pct, created_by)
             VALUES (?, ?, ?, '')
             ON DUPLICATE KEY UPDATE gmail = VALUES(gmail), cashback_pct = VALUES(cashback_pct)",
            [$clientId, $email, $this->normPct($cashbackPct)]
        );
    }

    /**
     * Local-only (de)activation. The Poster card and its sales history stay
     * intact; an inactive blogger is just hidden from the report. The promocode
     * and discount in Poster remain until the manager edits them.
     */
    public function setActive(int $clientId, bool $active): void
    {
        if ($clientId <= 0) {
            throw new \RuntimeException('Не указан блогер.');
        }
        $this->db->query(
            "INSERT INTO {$this->db->t('bloggers')} (poster_client_id, is_active) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)",
            [$clientId, $active ? 1 : 0]
        );
    }

    // ─── Poster helpers ────────────────────────────────────────────────

    /** @return list<array<string,mixed>> every client in the bloggers group. */
    private function posterClients(): array
    {
        $resp = $this->poster->client()->request('clients.getClients', [
            'group_id' => self::GROUP_ID,
            'num'      => 1000,
        ]);
        return is_array($resp) ? array_values($resp) : [];
    }

    /**
     * dash.getClientsSales for the period, keyed by client_id.
     * `revenue` = paid after discount (cashback base); `clients` = closed checks.
     *
     * @return array<int,array{checks:int,revenue:int}>
     */
    private function clientsSales(string $dateFrom, string $dateTo): array
    {
        $resp = $this->poster->client()->request('dash.getClientsSales', [
            'dateFrom' => str_replace('-', '', $dateFrom),
            'dateTo'   => str_replace('-', '', $dateTo),
        ]);
        $out = [];
        if (is_array($resp)) {
            foreach ($resp as $r) {
                $id = (int) ($r['client_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $out[$id] = [
                    'checks'  => (int) ($r['clients'] ?? 0),
                    'revenue' => (int) round((float) ($r['revenue'] ?? 0)),
                ];
            }
        }
        return $out;
    }

    /**
     * Promocode = the client's displayed name. We always write it via
     * `client_name` as a single token (→ lastname), but reconstruct robustly as
     * "lastname firstname" so a name typed directly in Poster still reads back.
     */
    private static function promocodeOf(array $c): string
    {
        $first = trim((string) ($c['firstname'] ?? ''));
        $last  = trim((string) ($c['lastname'] ?? ''));
        return trim($last . ($first !== '' ? ' ' . $first : ''));
    }

    // ─── Local table ───────────────────────────────────────────────────

    /** @return array<int,array{cashback_pct:float,gmail:string,is_active:int,created_by:string}> */
    private function localByClientId(): array
    {
        $rows = $this->db->query(
            "SELECT poster_client_id, gmail, cashback_pct, is_active, created_by FROM {$this->db->t('bloggers')}"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['poster_client_id']] = [
                'cashback_pct' => (float) $r['cashback_pct'],
                'gmail'        => (string) $r['gmail'],
                'is_active'    => (int) $r['is_active'],
                'created_by'   => (string) $r['created_by'],
            ];
        }
        return $out;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $t = $this->db->t('bloggers');
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$t} (
                id               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
                poster_client_id INT UNSIGNED   NOT NULL,
                gmail            VARCHAR(255)   NOT NULL DEFAULT '',
                cashback_pct     DECIMAL(5,2)   NOT NULL DEFAULT 0,
                is_active        TINYINT(1)     NOT NULL DEFAULT 1,
                created_by       VARCHAR(255)   NOT NULL DEFAULT '',
                created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_poster_client (poster_client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$schemaReady = true;
    }

    // ─── Validation ────────────────────────────────────────────────────

    private function normPct(float $p): float
    {
        return max(0.0, min(100.0, round($p, 2)));
    }

    /** Trimmed, no whitespace (must be typeable/searchable at the POS), ≤ 50 chars. */
    private function normPromocode(string $p): string
    {
        $p = self::posterSafe($p);
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
        foreach ($this->posterClients() as $c) {
            $id = (int) ($c['client_id'] ?? 0);
            if ($exceptClientId !== null && $id === $exceptClientId) {
                continue;
            }
            if (mb_strtolower(self::promocodeOf($c)) === $needle) {
                throw new \RuntimeException("Промокод «{$promocode}» уже занят другим блогером.");
            }
        }
    }

    /** Strip 4-byte UTF-8 (emoji / ZWJ / variation selectors) — Poster is utf8mb3. */
    private static function posterSafe(string $s): string
    {
        return trim((string) preg_replace('/[\x{10000}-\x{10FFFF}\x{FE0F}\x{200D}]/u', '', $s));
    }
}
