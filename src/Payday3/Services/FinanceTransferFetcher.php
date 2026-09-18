<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\Money;
use App\Payday3\Domain\PosterTime;

/**
 * Poster-facing half of the Финансовые транзакции card (and of the UPLD
 * duplicate check): live HTTP calls + normalisation of Poster's many
 * field-name variants. Extracted from FinanceTransferService, which is
 * left with the business rules only.
 *
 * Every result is memoised for the lifetime of THIS object — one PHP
 * request (the container shares the instance). Nothing is persisted:
 * the next request is live again. Before, one /finance/transfers call
 * fetched access.getEmployees and finance.getAccounts twice each (once
 * for vietnam(), once for tips()).
 */
final class FinanceTransferFetcher
{
    /** @var array<string, list<array<string,mixed>>> */
    private array $checksMemo = [];
    /** @var array<string, list<array<string,mixed>>> */
    private array $financeMemo = [];
    /** @var array<string, array<int,string>> */
    private array $mapMemo = [];

    public function __construct(private readonly PosterApiProviderInterface $poster) {}

    /**
     * Closed checks of the range from dash.getTransactions, normalised to
     * {transaction_id, pay_type, poster_payment_method_id, payed_card,
     * payed_third_party, tip_sum} — raw Poster minor units. tip_sum uses
     * the same formula as the stored poster_checks (PosterSyncService::tipSum).
     *
     * @return list<array{transaction_id:int,pay_type:int,poster_payment_method_id:int,payed_card:int,payed_third_party:int,tip_sum:int}>
     */
    public function closedChecks(DateRange $range): array
    {
        $key = $range->from . '..' . $range->to;
        if (isset($this->checksMemo[$key])) return $this->checksMemo[$key];

        $rows = $this->poster->client()->request('dash.getTransactions', [
            'dateFrom'         => str_replace('-', '', $range->from),
            'dateTo'           => str_replace('-', '', $range->to),
            'status'           => 2,
            'include_products' => 0,
            'include_history'  => 0,
        ]);
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $tx) {
            if (!is_array($tx)) continue;
            $txId = (int)($tx['transaction_id'] ?? $tx['id'] ?? 0);
            if ($txId <= 0) continue;
            $out[] = [
                'transaction_id'           => $txId,
                'pay_type'                 => (int)($tx['pay_type'] ?? $tx['payType'] ?? 0),
                'poster_payment_method_id' => (int)($tx['payment_method_id'] ?? $tx['paymentMethodId'] ?? 0),
                'payed_card'               => Money::toInt($tx['payed_card']        ?? $tx['payedCard']       ?? 0),
                'payed_third_party'        => Money::toInt($tx['payed_third_party'] ?? $tx['payedThirdParty'] ?? 0),
                'tip_sum'                  => PosterSyncService::tipSum($tx),
            ];
        }
        return $this->checksMemo[$key] = $out;
    }

    /**
     * finance.getTransactions for the range, filtered to rows whose
     * timestamp really lies inside it.
     *
     * Date format: Poster documents dateFrom/dateTo as Ymd. A dmY retry
     * was added for tenants that "returned empty" on Ymd — but an empty
     * day is the NORMAL state, and under dmY Poster ignores the range and
     * returns the account's whole history (2 extra heavy calls per page
     * open). The retry now happens only when the Ymd call ERRORS; the ts
     * guard below keeps the history dump harmless if it does.
     *
     * @param array<string,scalar> $filters extra params (account_id, type…)
     * @return list<array<string,mixed>>
     */
    public function financeTransactions(DateRange $range, array $filters = []): array
    {
        ksort($filters);
        $key = $range->from . '..' . $range->to . '|' . http_build_query($filters);
        if (isset($this->financeMemo[$key])) return $this->financeMemo[$key];

        $startTs = strtotime($range->from . ' 00:00:00');
        $endTs   = strtotime($range->to   . ' 23:59:59');
        if ($startTs === false || $endTs === false) return [];

        $api    = $this->poster->client();
        $params = $filters + ['timezone' => 'client'];
        try {
            $rows = $api->request('finance.getTransactions',
                ['dateFrom' => date('Ymd', $startTs), 'dateTo' => date('Ymd', $endTs)] + $params);
        } catch (\Throwable $e) {
            error_log('[payday3.finance] Ymd request failed, retrying dmY: ' . $e->getMessage());
            $rows = $api->request('finance.getTransactions',
                ['dateFrom' => date('dmY', $startTs), 'dateTo' => date('dmY', $endTs)] + $params);
        }

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) continue;
            $ts = self::rowTs($row);
            if ($ts === null || $ts < $startTs || $ts > $endTs) continue;
            $out[] = $row;
        }
        return $this->financeMemo[$key] = $out;
    }

    /**
     * Is there already a finance row on $date with this amount (compared in
     * VND — Poster returns cents) and a comment containing $commentNeedle
     * (case-insensitive)? Shared idempotency check of the Vietnam/Tips
     * transfer and the UPLD balance correction.
     *
     * @param array<string,scalar>             $requestFilters passed to finance.getTransactions
     * @param (callable(array): bool)|null     $rowFilter      extra per-row predicate
     */
    public function existsOnDay(string $date, int $amountVnd, string $commentNeedle, array $requestFilters = [], ?callable $rowFilter = null): bool
    {
        $needle = mb_strtolower($commentNeedle, 'UTF-8');
        foreach ($this->financeTransactions(DateRange::of($date, $date), $requestFilters) as $row) {
            if ($rowFilter !== null && !$rowFilter($row)) continue;
            if (abs(self::rowAmountVnd($row)) !== abs($amountVnd)) continue;
            $cmt = mb_strtolower((string)($row['comment'] ?? $row['description'] ?? ''), 'UTF-8');
            if ($cmt !== '' && $needle !== '' && mb_strpos($cmt, $needle) !== false) return true;
        }
        return false;
    }

    /** Account id stored in a Poster field that may be an int or {account_id|id}. */
    public static function accountField(mixed $v): int
    {
        if (is_array($v)) return (int)($v['account_id'] ?? $v['id'] ?? 0);
        return (int)$v;
    }

    /** @return array<int,string> user_id → name (access.getEmployees), memoised. */
    public function employeeNames(): array
    {
        return $this->nameMap('access.getEmployees', 'user_id', 'name');
    }

    /** @return array<int,string> account_id → name (finance.getAccounts), memoised. */
    public function accountNames(): array
    {
        return $this->nameMap('finance.getAccounts', 'account_id', 'name');
    }

    // ─── row normalisation (Poster field-name zoo) ──────────────

    /** Amount of a finance row in VND (Poster finance.* is in cents). */
    public static function rowAmountVnd(array $row): int
    {
        return Money::posterMinorToVnd(
            $row['amount'] ?? $row['amount_to'] ?? $row['amount_from'] ?? $row['sum'] ?? 0
        );
    }

    public static function rowTs(array $row): ?int
    {
        return PosterTime::toUnix(
            $row['date'] ?? $row['created_at'] ?? $row['createdAt'] ?? $row['time']
            ?? $row['datetime'] ?? $row['date_time'] ?? $row['created'] ?? null
        );
    }

    public static function rowUserId(array $row): int
    {
        $raw = $row['user_id'] ?? $row['userId'] ?? $row['user'] ?? $row['employee_id'] ?? null;
        if (is_array($raw)) $raw = $raw['user_id'] ?? $raw['id'] ?? $raw['userId'] ?? null;
        return (int)($raw ?? 0);
    }

    /**
     * Account id of a row as seen from $accTarget: for transfers/outgoings
     * the receiving side when it IS the target, else the sending side.
     */
    public static function rowAccountId(array $row, bool $isXfer, bool $isOut, int $accTarget): int
    {
        if ($isXfer) {
            $to = self::accountField($row['account_to'] ?? $row['account_to_id'] ?? $row['recipient_id'] ?? 0);
            if ($to === $accTarget) return $to;
            return self::accountField($row['account_from'] ?? $row['account_from_id'] ?? $row['account_id'] ?? 0);
        }
        if ($isOut) {
            $from = self::accountField($row['account_id'] ?? $row['accountId'] ?? $row['account_from_id']
                ?? $row['account_from'] ?? $row['accountFromId'] ?? $row['accountFrom'] ?? 0);
            $to   = self::accountField($row['recipient_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0);
            return $to === $accTarget ? $to : $from;
        }
        // IN: account_id is the receiving account.
        $to = self::accountField($row['account_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0);
        if ($to === $accTarget) return $to;
        return self::accountField($row['account_from'] ?? $row['account_from_id'] ?? 0);
    }

    /** @return array<int,string> */
    private function nameMap(string $method, string $idKey, string $nameKey): array
    {
        if (isset($this->mapMemo[$method])) return $this->mapMemo[$method];
        try {
            $rows = $this->poster->client()->request($method, []);
        } catch (\Throwable $e) {
            // Names are decoration: the card still works with "#id".
            error_log('[payday3.finance] ' . $method . ' failed: ' . $e->getMessage());
            return $this->mapMemo[$method] = [];
        }
        $map = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!is_array($r)) continue;
            $id = (int)($r[$idKey] ?? 0);
            if ($id > 0) $map[$id] = trim((string)($r[$nameKey] ?? ''));
        }
        return $this->mapMemo[$method] = $map;
    }
}
