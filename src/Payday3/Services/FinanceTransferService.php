<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Database;
use App\Payday3\Contracts\FinanceTransferServiceInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\Money;

/**
 * Финансовые транзакции (Vietnam Company + Tips). Two-step pipeline:
 *
 *   1. SQL aggregate over poster_checks to compute the expected
 *      transfer total in cents (then converted to VND).
 *   2. Poster API `finance.getTransactions` for the target account
 *      (Vietnam or Tips) within the range, normalised to a flat
 *      `{ts, sum_minor, type, comment, user, account, transaction_id}`
 *      shape ready for JSON encode.
 *
 * Vietnam's payment method id is hard-coded at 11 — same constant
 * payday2's Config::METHOD_VIETNAM uses. Andrey / Tips / Vietnam
 * account_ids come from the injected LocalSettings.
 */
final class FinanceTransferService implements FinanceTransferServiceInterface
{
    /** poster_payment_method_id for Vietnam Company. */
    private const METHOD_VIETNAM = 11;

    public function __construct(
        private readonly Database                         $db,
        private readonly PosterApiProviderInterface       $poster,
        private readonly LocalSettingsRepositoryInterface $settings,
    ) {}

    public function vietnam(DateRange $range): array
    {
        return [
            'total_vnd' => $this->vietnamExpectedVnd($range),
            'found'     => $this->fetchTransfers($range, 'vietnam'),
        ];
    }

    public function tips(DateRange $range): array
    {
        return [
            'total_vnd' => $this->tipsExpectedVnd($range),
            'found'     => $this->fetchTransfers($range, 'tips'),
        ];
    }

    public function createTransfer(string $kind, DateRange $range, string $byLabel = ''): array
    {
        if (!in_array($kind, ['vietnam', 'tips'], true)) {
            throw new \InvalidArgumentException('Unknown transfer kind: ' . $kind);
        }
        $cfg = $this->settings->load();
        if ($cfg->accountAndreyId <= 0 || $cfg->serviceUserId <= 0) {
            throw new \RuntimeException('Не настроены счета или service_user_id.');
        }
        $accountTo = $kind === 'vietnam' ? $cfg->accountVietnamId : $cfg->accountTipsId;
        if ($accountTo <= 0) {
            throw new \RuntimeException('Не настроен ' . ($kind === 'vietnam' ? 'accountVietnamId' : 'accountTipsId') . '.');
        }
        $amountVnd = $kind === 'vietnam'
            ? $this->vietnamExpectedVnd($range)
            : $this->tipsExpectedVnd($range);
        if ($amountVnd === null || $amountVnd <= 0) {
            throw new \RuntimeException($kind === 'vietnam'
                ? 'Сумма = 0: нет чеков Vietnam Company за выбранный период.'
                : 'Сумма = 0: нет типсов по связанным чекам за выбранный период.');
        }

        $targetDate = $range->to . ' 23:55:00';
        $commentBase = $kind === 'vietnam'
            ? 'Перевод чеков вьетнаской компании'
            : 'Перевод типсов';
        $comment = $commentBase . (trim($byLabel) !== '' ? ' by ' . trim($byLabel) : '');

        // Idempotency — same flow as BalanceSyncService::commit().
        // Walk the target account's same-day incomings; if any of
        // them matches our (type, amount, comment-prefix), we
        // consider the transfer already created.
        if ($this->alreadyExistsToday($accountTo, $amountVnd, $commentBase, $range)) {
            return [
                'ok'         => true,
                'already'    => true,
                'amount_vnd' => $amountVnd,
                'date'       => $targetDate,
                'comment'    => $comment,
                'user'       => '',
            ];
        }

        $payload = [
            'type'         => 2,            // 2 = transfer (Poster wire)
            'user_id'      => $cfg->serviceUserId,
            'account_from' => $cfg->accountAndreyId,
            'account_to'   => $accountTo,
            'amount_from'  => $amountVnd,
            'amount_to'    => $amountVnd,
            'date'         => $targetDate,
            'timezone'     => 'client',     // store in client (Vietnam) TZ, not server UTC
            'comment'      => $comment,
            // Legacy field names — payday2 sent both shapes to
            // survive Poster API changes across tenants.
            'account_id'   => $cfg->accountAndreyId,
            'account_to_id'=> $accountTo,
            'sum'          => $amountVnd,
        ];
        try {
            $this->poster->client()->request('finance.createTransactions', $payload, 'POST');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Poster: ' . $e->getMessage(), 0, $e);
        }
        return [
            'ok'         => true,
            'already'    => false,
            'amount_vnd' => $amountVnd,
            'date'       => $targetDate,
            'comment'    => $comment,
            'user'       => $byLabel,
        ];
    }

    private function alreadyExistsToday(int $accountTo, int $amountVnd, string $commentBase, DateRange $range): bool
    {
        $startTs = strtotime($range->to . ' 00:00:00');
        $endTs   = strtotime($range->to . ' 23:59:59');
        if ($startTs === false || $endTs === false) return false;

        $rows = $this->safeFinanceRequest($this->poster->client(), [
            'dateFrom'   => date('Ymd', $startTs),
            'dateTo'     => date('Ymd', $endTs),
            'account_id' => $accountTo,
            'type'       => 1,
            'timezone'   => 'client',
        ]);
        if ($rows === []) {
            // Poster's finance.getTransactions ignores the Ymd format on
            // some tenants → 0 rows. The dmY fallback exists for those —
            // BUT under dmY Poster returns the ENTIRE history of the
            // account, IGNORING dateFrom/dateTo. Without an explicit ts
            // guard below we'd flag any historical 50 000 "Перевод
            // типсов" as "already exists today" and refuse to create a
            // legitimate new one. See payday2/ajax.php `transfer_tips`,
            // which does the same per-row ts check.
            $rows = $this->safeFinanceRequest($this->poster->client(), [
                'dateFrom'   => date('dmY', $startTs),
                'dateTo'     => date('dmY', $endTs),
                'account_id' => $accountTo,
                'type'       => 1,
                'timezone'   => 'client',
            ]);
        }
        $needle = mb_strtolower($commentBase, 'UTF-8');
        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            // Date-range guard — same as fetchTransfers(). Without it
            // the dmY-fallback branch above lets every historical row
            // through, producing false positives for any past transfer
            // that happens to share today's amount.
            $ts = self::pickTs($row);
            if ($ts === null || $ts < $startTs || $ts > $endTs) continue;

            // normMoneyMinor already returns VND (the Poster-cents
            // heuristic divides by 100 internally) — no extra /100.
            // Matches payday2's normMoney() which is compared directly
            // to $amountVnd without a second division.
            $rawAmt = $row['amount'] ?? $row['amount_to'] ?? $row['amount_from'] ?? $row['sum'] ?? 0;
            $sumVnd = abs(self::normMoneyMinor($rawAmt));
            if ($sumVnd !== $amountVnd) continue;
            $cmt = mb_strtolower((string)($row['comment'] ?? $row['description'] ?? ''), 'UTF-8');
            if ($cmt !== '' && mb_strpos($cmt, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function vietnamExpectedVnd(DateRange $range): ?int
    {
        try {
            $pc = $this->db->t('poster_checks');
            $cents = (int)$this->db->query(
                "SELECT COALESCE(SUM(payed_card + payed_third_party + tip_sum), 0)
                 FROM {$pc}
                 WHERE day_date BETWEEN ? AND ?
                   AND pay_type IN (2,3)
                   AND (payed_card + payed_third_party) > 0
                   AND poster_payment_method_id = ?",
                [$range->from, $range->to, self::METHOD_VIETNAM]
            )->fetchColumn();
            return Money::fromPosterCents($cents)->amount;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function tipsExpectedVnd(DateRange $range): ?int
    {
        try {
            $pc = $this->db->t('poster_checks');
            $pl = $this->db->t('check_payment_links');
            $cents = (int)$this->db->query(
                "SELECT COALESCE(SUM(p.tip_sum), 0)
                 FROM {$pc} p
                 JOIN (
                    SELECT DISTINCT l.poster_transaction_id
                    FROM {$pl} l
                    JOIN {$pc} p2 ON p2.transaction_id = l.poster_transaction_id
                    WHERE p2.day_date BETWEEN ? AND ?
                      AND COALESCE(p2.was_deleted, 0) = 0
                 ) x ON x.poster_transaction_id = p.transaction_id
                 WHERE p.day_date BETWEEN ? AND ?
                   AND COALESCE(p.was_deleted, 0) = 0
                   AND p.pay_type IN (2,3)
                   AND (p.payed_card + p.payed_third_party) > 0
                   AND p.tip_sum > 0
                   AND COALESCE(p.poster_payment_method_id, 0) <> ?",
                [$range->from, $range->to, $range->from, $range->to, self::METHOD_VIETNAM]
            )->fetchColumn();
            return Money::fromPosterCents($cents)->amount;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param 'vietnam'|'tips' $kind
     * @return list<array{ts:int,sum_minor:int,type:string,comment:string,user:string,account:string,transaction_id:int}>
     */
    private function fetchTransfers(DateRange $range, string $kind): array
    {
        $cfg = $this->settings->load();
        $accTarget = $kind === 'vietnam' ? $cfg->accountVietnamId : $cfg->accountTipsId;
        if ($accTarget <= 0) return [];

        $startTs = strtotime($range->from . ' 00:00:00');
        $endTs   = strtotime($range->to   . ' 23:59:59');
        if ($startTs === false || $endTs === false) return [];

        $api = $this->poster->client();

        // Poster's finance.getTransactions accepts dateFrom/dateTo in Ymd. Some
        // hosts have returned empty for that format — fall back to dmY.
        $rows = $this->safeFinanceRequest($api, [
            'dateFrom'   => date('Ymd', $startTs),
            'dateTo'     => date('Ymd', $endTs),
            'account_id' => $accTarget,
            'type'       => 1,
            'timezone'   => 'client',
        ]);
        if ($rows === []) {
            $rows = $this->safeFinanceRequest($api, [
                'dateFrom'   => date('dmY', $startTs),
                'dateTo'     => date('dmY', $endTs),
                'account_id' => $accTarget,
                'type'       => 1,
                'timezone'   => 'client',
            ]);
        }

        $employees = $this->safeMap($api, 'access.getEmployees', 'user_id', 'name');
        $accounts  = $this->safeMap($api, 'finance.getAccounts', 'account_id', 'name');

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $tRaw   = (string)($row['type'] ?? '');
            $isXfer = ($tRaw === '2');
            $isIn   = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut  = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isXfer && !$isIn && !$isOut) continue;

            $ts = self::pickTs($row);
            if ($ts === null || $ts < $startTs || $ts > $endTs) continue;

            $accId = self::pickAccountId($row, $isXfer, $isOut, $accTarget);
            if ($accId !== $accTarget) continue;

            $sumMinor = abs(self::normMoneyMinor($row['amount']
                ?? $row['amount_to'] ?? $row['amount_from'] ?? $row['sum'] ?? 0));

            $uId = self::pickUserId($row);
            $userName = '';
            if ($uId > 0 && isset($employees[$uId])) {
                $userName = (string)$employees[$uId];
            } elseif (is_array($row['user'] ?? null)) {
                $u = $row['user'];
                $userName = trim((string)($u['name'] ?? $u['user_name'] ?? $u['username'] ?? $u['title'] ?? ''));
            }
            if ($userName === '' && $uId > 0) $userName = '#' . $uId;

            $accName = $accounts[$accId] ?? ('#' . $accId);

            $out[] = [
                'transaction_id' => (int)($row['transaction_id'] ?? $row['id'] ?? 0),
                'ts'             => $ts,
                'sum_minor'      => $sumMinor,
                'type'           => $tRaw,
                'comment'        => trim((string)($row['comment'] ?? $row['description'] ?? '')),
                'user'           => $userName,
                'account'        => (string)$accName,
            ];
        }
        usort($out, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
        return $out;
    }

    private function safeFinanceRequest($api, array $params): array
    {
        try {
            $rows = $api->request('finance.getTransactions', $params);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int,string> */
    private function safeMap($api, string $method, string $idKey, string $nameKey): array
    {
        try {
            $rows = $api->request($method, []);
        } catch (\Throwable $e) {
            return [];
        }
        $map = [];
        if (!is_array($rows)) return $map;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $id = (int)($r[$idKey] ?? 0);
            if ($id > 0) $map[$id] = trim((string)($r[$nameKey] ?? ''));
        }
        return $map;
    }

    private static function pickTs(array $row): ?int
    {
        $raw = $row['date'] ?? $row['created_at'] ?? $row['createdAt'] ?? $row['time']
             ?? $row['datetime'] ?? $row['date_time'] ?? $row['created'] ?? null;
        if (is_numeric($raw)) {
            $n = (int)$raw;
            if ($n > 2_000_000_000_000) $n = (int)round($n / 1000);
            return $n > 0 ? $n : null;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $t = strtotime($raw);
            return ($t !== false && $t > 0) ? $t : null;
        }
        return null;
    }

    private static function pickAccountId(array $row, bool $isXfer, bool $isOut, int $accTarget): int
    {
        $extract = static function (mixed $v): int {
            if (is_array($v)) {
                return (int)($v['account_id'] ?? $v['id'] ?? 0);
            }
            return (int)$v;
        };
        if ($isXfer) {
            $to = $extract($row['account_to'] ?? $row['account_to_id'] ?? $row['recipient_id'] ?? 0);
            if ($to === $accTarget) return $to;
            $from = $extract($row['account_from'] ?? $row['account_from_id'] ?? $row['account_id'] ?? 0);
            return $from;
        }
        if ($isOut) {
            $from = $extract($row['account_id'] ?? $row['accountId'] ?? $row['account_from_id']
                ?? $row['account_from'] ?? $row['accountFromId'] ?? $row['accountFrom'] ?? 0);
            $to   = $extract($row['recipient_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0);
            return $to === $accTarget ? $to : $from;
        }
        // IN: account_id is the receiving account.
        $to = $extract($row['account_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0);
        if ($to === $accTarget) return $to;
        return $extract($row['account_from'] ?? $row['account_from_id'] ?? 0);
    }

    private static function pickUserId(array $row): int
    {
        $raw = $row['user_id'] ?? $row['userId'] ?? $row['user'] ?? $row['employee_id'] ?? null;
        if (is_array($raw)) $raw = $raw['user_id'] ?? $raw['id'] ?? $raw['userId'] ?? null;
        return (int)($raw ?? 0);
    }

    /**
     * Poster sometimes returns amounts as VND (200_000_000) and other
     * times as cents (200_000_000_00). Heuristic mirrors payday2:
     * if the value is suspiciously large AND divisible by 100, treat
     * it as cents and downscale.
     */
    private static function normMoneyMinor(mixed $raw): int
    {
        $f = 0.0;
        if (is_int($raw) || is_float($raw)) $f = (float)$raw;
        elseif (is_string($raw)) $f = (float)str_replace(',', '.', str_replace(' ', '', trim($raw)));
        $n = (int)round($f);
        return ($n > 200_000_000 && $n % 100 === 0) ? (int)round($n / 100) : $n;
    }
}
