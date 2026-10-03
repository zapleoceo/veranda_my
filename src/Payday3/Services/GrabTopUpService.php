<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\ActualBalanceRepositoryInterface;
use App\Payday3\Contracts\AuditLogInterface;
use App\Payday3\Contracts\FinanceTransferServiceInterface;
use App\Payday3\Contracts\GrabTopUpServiceInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\NamedLockInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterBalanceServiceInterface;
use App\Payday3\Domain\Actor;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\PosterIds;

/**
 * «Пополнить Grab» — the third row of the Финансовые транзакции card.
 *
 *   surplus = saved Факт. «Вьет.» (payday_actual_balances, latestFor(range.to))
 *             − live Poster balance of accountVietnamId
 *           = Δ of the «Вьет.» row in «Итоговый баланс».
 *
 * Allowed only when surplus > 0, Vietnam AND Tips are reconciled
 * (FinanceTransferService::isReconciled — their Poster transaction exists
 * with the expected amount) and no GRAB income is booked on that day yet.
 * create() recomputes all of it under a named lock — the browser value is
 * never trusted — then posts a finance income (type 1, category GRAB) to
 * the Vietnam account and leaves an audit row.
 */
final class GrabTopUpService implements GrabTopUpServiceInterface
{
    public const COMMENT_BASE = 'Пополнение Grab';
    private const LOCK_TIMEOUT_S       = 5;
    private const IDEMPOTENCY_WINDOW_S = 600;

    public function __construct(
        private readonly FinanceTransferServiceInterface  $transfers,
        private readonly FinanceTransferFetcher           $fetcher,
        private readonly ActualBalanceRepositoryInterface $actual,
        private readonly PosterBalanceServiceInterface    $balances,
        private readonly LocalSettingsRepositoryInterface $settings,
        private readonly PosterApiProviderInterface       $poster,
        private readonly NamedLockInterface               $lock,
        private readonly AuditLogInterface                $audit,
    ) {}

    public function status(DateRange $range): array
    {
        try {
            return $this->evaluate($range);
        } catch (\Throwable $e) {
            error_log('[payday3.finance_transfers] grab status failed: ' . $e->getMessage());
            return [
                'surplus_vnd' => null, 'fact_vnd' => null, 'poster_vnd' => null,
                'vietnam_ok'  => false, 'tips_ok' => false, 'found' => [],
                'reason'      => 'error', 'can_create' => false,
                'message'     => 'Не удалось загрузить данные из Poster',
                'error'       => 'Не удалось загрузить данные из Poster',
            ];
        }
    }

    public function create(DateRange $range, Actor $actor): array
    {
        $cfg = $this->settings->load();
        if ($cfg->accountVietnamId <= 0 || $cfg->serviceUserId <= 0) {
            throw new \DomainException('Не настроены accountVietnamId или service_user_id.');
        }
        return $this->lock->synchronized(
            'payday_fin_transfer_grab' . $range->to,
            self::LOCK_TIMEOUT_S,
            fn() => $this->createLocked($range, $actor),
        );
    }

    private function createLocked(DateRange $range, Actor $actor): array
    {
        // Full re-validation — Poster failures propagate (→ 502), never
        // silently turn into "allowed".
        $st = $this->evaluate($range, strict: true);
        if (!$st['can_create']) {
            throw new \DomainException($st['message']);
        }
        $amountVnd = (int)$st['surplus_vnd'];
        $cfg       = $this->settings->load();
        $date      = $range->to . ' 23:55:00';           // same as Vietnam/Tips transfers
        $comment   = self::comment($range->to);

        // Second line of defence when Poster's list lags behind a create.
        $fingerprint = hash('sha256', 'grab_topup|' . $range->to . '|' . $amountVnd);
        if ($this->audit->existsRecent($fingerprint, self::IDEMPOTENCY_WINDOW_S)) {
            throw new \DomainException('Пополнение Grab на эту сумму только что создано — повтор отклонён. Проверьте Poster.');
        }

        $payload = [
            'id'         => 1,
            'type'       => 1,                            // 1 = income
            'category'   => PosterIds::CATEGORY_GRAB,
            'user_id'    => $cfg->serviceUserId,
            'account_to' => $cfg->accountVietnamId,
            'amount_to'  => number_format($amountVnd, 2, '.', ''),   // VND, "1234.00" — as the UPLD income
            'date'       => $date,
            'timezone'   => 'client',
            'comment'    => $comment,
        ];
        try {
            $resp = $this->poster->client()->request('finance.createTransactions', $payload, 'POST');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Poster: ' . $e->getMessage(), 0, $e);
        }
        try {
            $this->audit->record($actor->email, 'finance.grab_topup', [
                'request'    => $payload,
                'fact_vnd'   => $st['fact_vnd'],
                'poster_vnd' => $st['poster_vnd'],
                'response'   => $resp,
            ], $fingerprint);
        } catch (\Throwable $e) {
            // The Poster transaction exists — don't report failure for it.
            error_log('[payday3.grab_topup] audit write failed: ' . $e->getMessage());
        }
        return [
            'ok'         => true,
            'already'    => false,
            'amount_vnd' => $amountVnd,
            'date'       => $date,
            'comment'    => $comment,
            'user'       => $actor->email,
        ];
    }

    /** @param bool $strict rethrow Poster failures instead of degrading */
    private function evaluate(DateRange $range, bool $strict = false): array
    {
        $cfg = $this->settings->load();

        // Only a Факт. saved FOR range.to counts — latestFor() falls back to
        // an older day, whose «Вьет.» would give a stale surplus.
        $snap   = $this->actual->latestFor($range->to);
        $fact   = ($snap !== null && $snap->targetDate === $range->to) ? $snap->vietnam?->amount : null;
        $poster = $this->balances->snapshot()['vietnam'] ?? null;
        $surplus = ($fact === null || $poster === null) ? null : $fact - (int)$poster;

        $vietnam = $this->transfers->vietnam($range);
        $tips    = $this->transfers->tips($range);
        if ($strict) {
            foreach (['Vietnam' => $vietnam, 'Tips' => $tips] as $name => $card) {
                if (isset($card['error'])) {
                    throw new \RuntimeException('Poster (' . $name . '): ' . $card['error']);
                }
            }
        }
        $vOk = FinanceTransferService::isReconciled($vietnam);
        $tOk = FinanceTransferService::isReconciled($tips);

        $found  = $this->existingGrab($range->to, $cfg->accountVietnamId);
        $reason = self::decide($fact, $poster === null ? null : (int)$poster, $vOk, $tOk, $found !== []);

        // No Факт. for this day yet, but the latest one (the value the
        // balances card shows) leaves no surplus → nothing to top up, the
        // row is closed. A positive carried-over surplus still asks for
        // today's Факт. before anything can be booked.
        $carried = $snap?->vietnam?->amount;
        if ($reason === 'no_fact' && $carried !== null && $poster !== null
            && $carried - (int)$poster <= 0) {
            $reason  = 'no_surplus';
            $surplus = $carried - (int)$poster;
        }

        return [
            'surplus_vnd' => $surplus,
            'fact_vnd'    => $fact,
            'poster_vnd'  => $poster === null ? null : (int)$poster,
            'vietnam_ok'  => $vOk,
            'tips_ok'     => $tOk,
            'found'       => $found,
            'reason'      => $reason,
            'can_create'  => $reason === 'ok',
            'message'     => self::message($reason, $range->to, $surplus, $found),
        ];
    }

    /**
     * Pure rule (unit-tested). Order: an existing GRAB income wins; then
     * missing inputs; then reconciliation (until Vietnam is transferred
     * the Poster balance — hence the surplus — is not final); then sign.
     */
    public static function decide(?int $factVnd, ?int $posterVnd, bool $vietnamOk, bool $tipsOk, bool $exists): string
    {
        if ($exists)                  return 'exists';
        if ($factVnd === null)        return 'no_fact';
        if ($posterVnd === null)      return 'no_poster';
        if (!$vietnamOk || !$tipsOk)  return 'not_reconciled';
        if ($factVnd - $posterVnd <= 0) return 'no_surplus';
        return 'ok';
    }

    public static function comment(string $dateYmd): string
    {
        return self::COMMENT_BASE . ' ' . self::dmy($dateYmd);
    }

    private static function dmy(string $dateYmd): string
    {
        $ts = strtotime($dateYmd);
        return $ts === false ? $dateYmd : date('d.m.Y', $ts);
    }

    /** Russian status / rejection text for a reason. */
    public static function message(string $reason, string $date, ?int $surplus, array $found = []): string
    {
        $n = static fn(int $v): string => number_format($v, 0, '.', ' ');
        return match ($reason) {
            'ok'             => 'Излишек ' . $n((int)$surplus) . ' — можно пополнить',
            'exists'         => 'Пополнение Grab за ' . $date . ' уже есть в Poster'
                                . (isset($found[0]) ? ': #' . $found[0]['transaction_id'] . ' ' . $n((int)$found[0]['sum_minor']) : '') . '.',
            'no_fact'        => 'Факт. «Вьет.» не сохранён за ' . self::dmy($date),
            'no_poster'      => 'Нет баланса Poster по счёту Vietnam — обновите балансы (↻).',
            'not_reconciled' => 'Сначала сведите Vietnam и Tips: их транзакции в Poster должны совпасть с ожидаемыми суммами.',
            'no_surplus'     => 'Излишка на счёте Vietnam нет' . ($surplus !== null ? ' (Δ ' . $n($surplus) . ')' : '') . '.',
            default          => 'Не удалось загрузить данные из Poster',
        };
    }

    /**
     * Pure rule: is a finance.getTransactions row a GRAB top-up — category
     * GRAB, or (Poster may omit the category) our comment prefix.
     * Also used to keep these rows out of the Vietnam card.
     */
    public static function isGrabRow(array $row): bool
    {
        if (FinanceTransferFetcher::rowCategoryId($row) === PosterIds::CATEGORY_GRAB) return true;
        $cmt = mb_strtolower((string)($row['comment'] ?? $row['description'] ?? ''), 'UTF-8');
        return $cmt !== '' && mb_strpos($cmt, mb_strtolower(self::COMMENT_BASE, 'UTF-8')) !== false;
    }

    /**
     * GRAB incomes on the Vietnam account on $date: category GRAB, or —
     * if Poster omits the category in the row — our comment prefix.
     */
    private function existingGrab(string $date, int $accountId): array
    {
        if ($accountId <= 0) return [];
        $rows = array_values(array_filter(
            $this->fetcher->financeTransactions(DateRange::of($date, $date), ['account_id' => $accountId, 'type' => 1]),
            [self::class, 'isGrabRow'],
        ));
        // Only real incomes (a GRAB-category row could also be a transfer/expense).
        return array_values(array_filter(
            $this->fetcher->cardRows($rows, $accountId),
            static fn(array $r) => $r['type'] === '1' || strtolower($r['type']) === 'in' || strtoupper($r['type']) === 'I',
        ));
    }
}
