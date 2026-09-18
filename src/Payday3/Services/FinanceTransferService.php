<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\FinanceTransferServiceInterface;
use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\NamedLockInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\Money;
use App\Payday3\Domain\PosterIds;

/**
 * Финансовые транзакции (Vietnam Company + Tips) — business rules only.
 *
 *   expected sums  — live closed checks (FinanceTransferFetcher, memoised
 *                    per request) + the local check_payment_links map for
 *                    Tips (LinkRepositoryInterface::linkedPosterIds);
 *   found          — finance.getTransactions of the target account;
 *   createTransfer — idempotent Poster transfer, serialised with a MySQL
 *                    named lock so two parallel clicks can't both pass the
 *                    "already exists?" check and create two transfers.
 *
 * Poster HTTP + field-shape normalisation live in FinanceTransferFetcher,
 * SQL in the link repository. Andrey / Tips / Vietnam account_ids come
 * from LocalSettings; the Vietnam payment method id from PosterIds.
 */
final class FinanceTransferService implements FinanceTransferServiceInterface
{
    private const LOCK_TIMEOUT_S = 5;

    public function __construct(
        private readonly FinanceTransferFetcher           $fetcher,
        private readonly LinkRepositoryInterface          $links,
        private readonly LocalSettingsRepositoryInterface $settings,
        private readonly PosterApiProviderInterface       $poster,
        private readonly NamedLockInterface               $lock,
    ) {}

    public function vietnam(DateRange $range): array
    {
        return $this->card($range, 'vietnam');
    }

    public function tips(DateRange $range): array
    {
        return $this->card($range, 'tips');
    }

    /**
     * Card payload. A Poster failure no longer silently turns into "—" /
     * "nothing found": the value stays null/[] (UI contract) but the reason
     * is logged and returned in `error`.
     */
    private function card(DateRange $range, string $kind): array
    {
        $errors = [];
        try {
            $total = $this->expectedVnd($range, $kind);
        } catch (\Throwable $e) {
            error_log('[payday3.finance_transfers] ' . $kind . ' expected sum failed: ' . $e->getMessage());
            $total    = null;
            $errors[] = 'Не удалось посчитать сумму из Poster';
        }
        try {
            $found = $this->fetchTransfers($range, $kind);
        } catch (\Throwable $e) {
            error_log('[payday3.finance_transfers] ' . $kind . ' transfers fetch failed: ' . $e->getMessage());
            $found    = [];
            $errors[] = 'Не удалось загрузить переводы из Poster';
        }
        $out = ['total_vnd' => $total, 'found' => $found];
        if ($errors !== []) $out['error'] = implode('; ', $errors);
        return $out;
    }

    public function createTransfer(string $kind, DateRange $range, string $byLabel = ''): array
    {
        if (!in_array($kind, ['vietnam', 'tips'], true)) {
            throw new \InvalidArgumentException('Unknown transfer kind: ' . $kind);
        }
        $cfg = $this->settings->load();
        if ($cfg->accountAndreyId <= 0 || $cfg->serviceUserId <= 0) {
            throw new \DomainException('Не настроены счета или service_user_id.');
        }
        $accountTo = $kind === 'vietnam' ? $cfg->accountVietnamId : $cfg->accountTipsId;
        if ($accountTo <= 0) {
            throw new \DomainException('Не настроен ' . ($kind === 'vietnam' ? 'accountVietnamId' : 'accountTipsId') . '.');
        }

        // check + create under one lock (per kind and day).
        return $this->lock->synchronized(
            'payday_fin_transfer_' . $kind . $range->to,
            self::LOCK_TIMEOUT_S,
            fn() => $this->createLocked($kind, $range, $byLabel, $accountTo),
        );
    }

    private function createLocked(string $kind, DateRange $range, string $byLabel, int $accountTo): array
    {
        $cfg       = $this->settings->load();
        $amountVnd = $this->expectedVnd($range, $kind);
        if ($amountVnd <= 0) {
            throw new \DomainException($kind === 'vietnam'
                ? 'Сумма = 0: нет чеков Vietnam Company за выбранный период.'
                : 'Сумма = 0: нет типсов по связанным чекам за выбранный период.');
        }

        $targetDate  = $range->to . ' 23:55:00';
        $commentBase = $kind === 'vietnam'
            ? 'Перевод чеков вьетнаской компании'
            : 'Перевод типсов';
        $comment = $commentBase . (trim($byLabel) !== '' ? ' by ' . trim($byLabel) : '');

        // Idempotency: same-day incoming on the target account with the
        // same amount (VND) and comment prefix ⇒ already created.
        $exists = $this->fetcher->existsOnDay(
            $range->to, $amountVnd, $commentBase,
            ['account_id' => $accountTo, 'type' => 1],
        );
        if ($exists) {
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

    /**
     * Expected transfer in VND.
     *
     *   vietnam — (card + third party + tips) of closed checks paid with the
     *             Vietnam Company method (pay_type 2|3, card part > 0);
     *   tips    — tips of NON-Vietnam card checks that are reconciled with a
     *             bank payment (check_payment_links). Vietnam tips already
     *             travel with the Vietnam transfer.
     */
    private function expectedVnd(DateRange $range, string $kind): int
    {
        $checks = $this->fetcher->closedChecks($range);
        return $kind === 'vietnam'
            ? self::vietnamSumVnd($checks)
            : self::tipsSumVnd($checks, fn(array $ids) => $this->links->linkedPosterIds($ids));
    }

    /**
     * Pure rule (unit-tested): Vietnam Company expected transfer.
     *
     * @param list<array<string,int>> $checks FinanceTransferFetcher::closedChecks() rows (minor units)
     */
    public static function vietnamSumVnd(array $checks): int
    {
        $cents = 0;
        foreach ($checks as $tx) {
            if (!self::isCardCheck($tx)) continue;
            if ((int)$tx['poster_payment_method_id'] !== PosterIds::METHOD_VIETNAM) continue;
            $cents += (int)$tx['payed_card'] + (int)$tx['payed_third_party'] + (int)$tx['tip_sum'];
        }
        return Money::fromPosterCents($cents)->amount;
    }

    /**
     * Pure rule (unit-tested): Tips expected transfer.
     *
     * @param list<array<string,int>>          $checks
     * @param callable(list<int>): list<int>   $linkedIds which of the ids have a bank link
     */
    public static function tipsSumVnd(array $checks, callable $linkedIds): int
    {
        $candidates = [];
        foreach ($checks as $tx) {
            if (!self::isCardCheck($tx)) continue;
            if ((int)$tx['poster_payment_method_id'] === PosterIds::METHOD_VIETNAM) continue;
            if ((int)$tx['tip_sum'] <= 0) continue;
            $candidates[(int)$tx['transaction_id']] = (int)$tx['tip_sum'];
        }
        if ($candidates === []) return 0;

        $cents = 0;
        foreach (array_unique($linkedIds(array_keys($candidates))) as $tid) {
            $cents += $candidates[(int)$tid] ?? 0;
        }
        return Money::fromPosterCents($cents)->amount;
    }

    private static function isCardCheck(array $tx): bool
    {
        return in_array((int)($tx['pay_type'] ?? 0), [2, 3], true)
            && ((int)($tx['payed_card'] ?? 0) + (int)($tx['payed_third_party'] ?? 0)) > 0;
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

        // Already ts-filtered to the range by the fetcher.
        $rows = $this->fetcher->financeTransactions($range, ['account_id' => $accTarget, 'type' => 1]);
        if ($rows === []) return [];

        // Memoised per request — shared by vietnam() and tips().
        $employees = $this->fetcher->employeeNames();
        $accounts  = $this->fetcher->accountNames();

        $out = [];
        foreach ($rows as $row) {
            $tRaw   = (string)($row['type'] ?? '');
            $isXfer = ($tRaw === '2');
            $isIn   = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut  = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isXfer && !$isIn && !$isOut) continue;

            $accId = FinanceTransferFetcher::rowAccountId($row, $isXfer, $isOut, $accTarget);
            if ($accId !== $accTarget) continue;

            $uId = FinanceTransferFetcher::rowUserId($row);
            $userName = '';
            if ($uId > 0 && isset($employees[$uId])) {
                $userName = (string)$employees[$uId];
            } elseif (is_array($row['user'] ?? null)) {
                $u = $row['user'];
                $userName = trim((string)($u['name'] ?? $u['user_name'] ?? $u['username'] ?? $u['title'] ?? ''));
            }
            if ($userName === '' && $uId > 0) $userName = '#' . $uId;

            $out[] = [
                'transaction_id' => (int)($row['transaction_id'] ?? $row['id'] ?? 0),
                'ts'             => (int)FinanceTransferFetcher::rowTs($row),
                // Name kept for the wire contract; the value is VND.
                'sum_minor'      => abs(FinanceTransferFetcher::rowAmountVnd($row)),
                'type'           => $tRaw,
                'comment'        => trim((string)($row['comment'] ?? $row['description'] ?? '')),
                'user'           => $userName,
                'account'        => (string)($accounts[$accId] ?? ('#' . $accId)),
            ];
        }
        usort($out, static fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
        return $out;
    }
}
