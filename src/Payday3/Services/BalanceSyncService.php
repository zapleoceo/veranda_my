<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\ActualBalanceRepositoryInterface;
use App\Payday3\Contracts\BalanceSyncServiceInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterBalanceServiceInterface;
use App\Payday3\Contracts\SessionStoreInterface;
use App\Payday3\Domain\PosterIds;

/**
 * Plan/commit pair backing the "UPLD" button on the Итоговый баланс
 * card. The correction is a finance.createTransactions call against
 * the configured `balance_sinc_account_id` with a fixed category and
 * comment — direct port of payday2/ajax.php (balance_sinc_plan and
 * balance_sinc_commit blocks).
 *
 * Why two endpoints: the operator first sees a human-readable preview
 * ("Начислить 12 000 на счёт 8 (Tips)?"), then confirms with a
 * separate POST. A nonce stashed in the session ties the two calls
 * together and acts as a 5-minute TTL — old previews can't be
 * replayed and a stale browser tab can't double-spend.
 *
 * The amount is computed HERE (saved Факт. by Андрей − live Poster
 * Андрей+Tips), never taken from the browser: the client value is only
 * a cross-check. commit() uses the amount stored with the nonce.
 */
final class BalanceSyncService implements BalanceSyncServiceInterface
{
    private const SESSION_KEY  = 'pd3_balance_sync';
    private const NONCE_TTL_S  = 300;
    private const COMMENT_BASE = 'Коррекция излишек - недостачи за счет чая';

    public function __construct(
        private readonly PosterApiProviderInterface       $poster,
        private readonly LocalSettingsRepositoryInterface $settings,
        private readonly SessionStoreInterface            $session,
        private readonly ActualBalanceRepositoryInterface $actual,
        private readonly PosterBalanceServiceInterface    $balances,
        private readonly FinanceTransferFetcher           $finance,
    ) {}

    public function plan(int $diffVnd, string $byLabel = '', ?string $targetDate = null): array
    {
        $cfg = $this->settings->load();
        if ($cfg->balanceSyncAccountId <= 0) {
            throw new \DomainException('balance_sinc_account_id не настроен');
        }
        if ($cfg->serviceUserId <= 0) {
            throw new \DomainException('service_user_id не настроен');
        }

        $date = ($targetDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) === 1)
            ? $targetDate : date('Y-m-d');
        $saved = $this->actual->latestFor($date)?->andrey;
        if ($saved === null) {
            throw new \DomainException('Факт. по Андрею не сохранён за ' . $date . '.');
        }
        // Poster calls happen with the session lock released (the store
        // only opens it for the final write).
        $snap   = $this->balances->snapshot();
        $poster = $snap['andrey'] ?? null;
        if ($poster === null) {
            throw new \DomainException('Нет баланса Poster по Андрею — нажмите ↻.');
        }
        $serverDiff = $saved->amount - (int)$poster;
        if ($diffVnd !== 0 && $diffVnd !== $serverDiff) {
            throw new \DomainException(sprintf(
                'Разница изменилась: на сервере %s, в браузере %s — обновите балансы (↻).',
                number_format($serverDiff, 0, '.', ' '), number_format($diffVnd, 0, '.', ' '),
            ));
        }
        if ($serverDiff === 0) {
            throw new \InvalidArgumentException('Разница = 0');
        }

        $type      = $serverDiff > 0 ? 1 : 0;
        $amountVnd = abs($serverDiff);
        $sum       = number_format($amountVnd, 2, '.', '');   // "1234.00"
        $accName   = self::accountName($snap['accounts'] ?? [], $cfg->balanceSyncAccountId);
        $comment   = self::COMMENT_BASE . ($byLabel !== '' ? ' by ' . trim($byLabel) : '');

        $nonce = bin2hex(random_bytes(16));
        $this->session->set(self::SESSION_KEY, [
            'nonce'      => $nonce,
            'diff_vnd'   => $serverDiff,
            'comment'    => $comment,
            'created_at' => time(),
        ]);

        return [
            'nonce' => $nonce,
            'plan'  => [
                'type'         => $type,
                'account_id'   => $cfg->balanceSyncAccountId,
                'account_name' => $accName,
                'amount_vnd'   => $amountVnd,
                'sum'          => $sum,
                'comment'      => $comment,
                'user_id'      => $cfg->serviceUserId,
                'diff_vnd'     => $serverDiff,
            ],
        ];
    }

    public function commit(string $nonce): array
    {
        $nonce = trim($nonce);
        if ($nonce === '') {
            throw new \InvalidArgumentException('Нет подтверждения (nonce)');
        }
        $st = $this->session->get(self::SESSION_KEY);
        if (!is_array($st) || !hash_equals((string)($st['nonce'] ?? ''), $nonce)) {
            throw new \DomainException('Подтверждение устарело');
        }
        // One-shot: consume the nonce BEFORE the Poster calls, so a second
        // (double-click) commit can't reuse it while the first is running.
        $this->session->remove(self::SESSION_KEY);

        $createdAt = (int)($st['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > self::NONCE_TTL_S) {
            throw new \DomainException('Подтверждение истекло');
        }
        $diffVnd = (int)($st['diff_vnd'] ?? 0);
        $comment = (string)($st['comment'] ?? self::COMMENT_BASE);
        if ($diffVnd === 0) {
            throw new \DomainException('Разница = 0');
        }

        $cfg       = $this->settings->load();
        $type      = $diffVnd > 0 ? 1 : 0;
        $amountVnd = abs($diffVnd);
        $sum       = number_format($amountVnd, 2, '.', '');
        $accountId = $cfg->balanceSyncAccountId;

        // Idempotency guard — if the previous request already committed
        // but the browser retried, don't create a duplicate transaction.
        if ($this->alreadyExistsToday($type, $accountId, $amountVnd)) {
            return ['ok' => true, 'already' => true];
        }

        $payload = [
            'id'       => 1,
            'type'     => $type,
            'category' => PosterIds::CATEGORY_BALANCE_CORRECTION,
            'user_id'  => $cfg->serviceUserId,
            // Generate the timestamp in Vietnam time and explicitly tell
            // Poster to read it as the account's timezone (=client). The
            // app sets Asia/Ho_Chi_Minh globally in Bootstrap so date()
            // returns Vietnam-local already; the 'timezone' flag is
            // defence-in-depth in case bootstrap is bypassed (CLI tools).
            'date'     => date('Y-m-d H:i:s'),
            'timezone' => 'client',
            'comment'  => $comment,
        ];
        if ($type === 1) {
            $payload['account_to'] = $accountId;
            $payload['amount_to']  = $sum;
        } else {
            $payload['account_from'] = $accountId;
            $payload['amount_from']  = $sum;
        }

        $resp = $this->poster->client()->request('finance.createTransactions', $payload, 'POST');
        return ['ok' => true, 'response' => $resp];
    }

    /** @param list<array{account_id:int,name:string}> $accounts */
    private static function accountName(array $accounts, int $accountId): string
    {
        foreach ($accounts as $a) {
            if ((int)($a['account_id'] ?? 0) === $accountId) return trim((string)($a['name'] ?? ''));
        }
        return '';
    }

    /**
     * Today's matching correction — same account / type / amount and a
     * comment containing COMMENT_BASE.
     *
     * Amounts are compared in VND: finance.getTransactions returns CENTS,
     * the old string comparison against "1234.00" could never match, so
     * a retried commit posted a second correction. A Poster error now
     * propagates (the operator retries) instead of meaning "no duplicate".
     */
    private function alreadyExistsToday(int $type, int $accountId, int $amountVnd): bool
    {
        return $this->finance->existsOnDay(
            date('Y-m-d'),
            $amountVnd,
            self::COMMENT_BASE,
            [],
            static function (array $r) use ($type, $accountId): bool {
                if ((int)($r['type'] ?? -1) !== $type) return false;
                $acc = $type === 1
                    ? FinanceTransferFetcher::accountField($r['account_to']   ?? $r['account_to_id']   ?? 0)
                    : FinanceTransferFetcher::accountField($r['account_from'] ?? $r['account_from_id'] ?? 0);
                return $acc === $accountId;
            },
        );
    }
}
