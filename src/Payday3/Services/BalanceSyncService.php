<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\BalanceSyncServiceInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;

/**
 * Plan/commit pair backing the "UPLD" button on the Итоговый баланс
 * card. The correction is a finance.createTransactions call against
 * the configured `balance_sinc_account_id` with a fixed category and
 * comment — direct port of payday2/ajax.php (balance_sinc_plan and
 * balance_sinc_commit blocks).
 *
 * Why two endpoints: the operator first sees a human-readable preview
 * ("Начислить 12 000 на счёт 8 (Tips)?"), then confirms with a
 * separate POST. A nonce stashed in $_SESSION ties the two calls
 * together and acts as a 5-minute TTL — old previews can't be
 * replayed and a stale browser tab can't double-spend.
 */
final class BalanceSyncService implements BalanceSyncServiceInterface
{
    private const SESSION_KEY  = 'pd3_balance_sinc';
    private const NONCE_TTL_S  = 300;
    private const CATEGORY_ID  = 4;
    private const COMMENT_BASE = 'Коррекция излишек - недостачи за счет чая';

    public function __construct(
        private readonly PosterApiProviderInterface       $poster,
        private readonly LocalSettingsRepositoryInterface $settings,
    ) {}

    public function plan(int $diffVnd, string $byLabel = ''): array
    {
        if ($diffVnd === 0) {
            throw new \InvalidArgumentException('Разница = 0');
        }
        $cfg = $this->settings->load();
        if ($cfg->balanceSyncAccountId <= 0) {
            throw new \RuntimeException('balance_sinc_account_id не настроен');
        }
        if ($cfg->serviceUserId <= 0) {
            throw new \RuntimeException('service_user_id не настроен');
        }

        $type      = $diffVnd > 0 ? 1 : 0;
        $amountVnd = abs($diffVnd);
        $sum       = number_format($amountVnd, 2, '.', '');   // "1234.00"
        $accName   = $this->accountName($cfg->balanceSyncAccountId);
        $comment   = self::COMMENT_BASE . ($byLabel !== '' ? ' by ' . trim($byLabel) : '');

        $nonce = bin2hex(random_bytes(16));
        self::sessionStart();
        $_SESSION[self::SESSION_KEY] = [
            'nonce'      => $nonce,
            'diff_vnd'   => $diffVnd,
            'comment'    => $comment,
            'created_at' => time(),
        ];

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
                'diff_vnd'     => $diffVnd,
            ],
        ];
    }

    public function commit(string $nonce): array
    {
        $nonce = trim($nonce);
        if ($nonce === '') {
            throw new \InvalidArgumentException('Нет подтверждения (nonce)');
        }
        self::sessionStart();
        $st = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($st) || (string)($st['nonce'] ?? '') !== $nonce) {
            throw new \RuntimeException('Подтверждение устарело');
        }
        $createdAt = (int)($st['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > self::NONCE_TTL_S) {
            unset($_SESSION[self::SESSION_KEY]);
            throw new \RuntimeException('Подтверждение истекло');
        }
        $diffVnd = (int)($st['diff_vnd'] ?? 0);
        $comment = (string)($st['comment'] ?? self::COMMENT_BASE);
        if ($diffVnd === 0) {
            unset($_SESSION[self::SESSION_KEY]);
            throw new \RuntimeException('Разница = 0');
        }

        $cfg       = $this->settings->load();
        $type      = $diffVnd > 0 ? 1 : 0;
        $amountVnd = abs($diffVnd);
        $sum       = number_format($amountVnd, 2, '.', '');
        $accountId = $cfg->balanceSyncAccountId;

        // Idempotency guard — if the operator double-clicks or the
        // previous request already committed but the browser retried,
        // we don't want to create a duplicate transaction.
        if ($this->alreadyExistsToday($type, $accountId, $sum, $comment)) {
            unset($_SESSION[self::SESSION_KEY]);
            return ['ok' => true, 'already' => true];
        }

        $payload = [
            'id'       => 1,
            'type'     => $type,
            'category' => self::CATEGORY_ID,
            'user_id'  => $cfg->serviceUserId,
            'date'     => date('Y-m-d H:i:s'),
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
        unset($_SESSION[self::SESSION_KEY]);
        return ['ok' => true, 'response' => $resp];
    }

    private function accountName(int $accountId): string
    {
        try {
            $rows = $this->poster->client()->request('finance.getAccounts', []);
        } catch (\Throwable $e) {
            return '';
        }
        if (!is_array($rows)) return '';
        foreach ($rows as $r) {
            if (is_array($r) && (int)($r['account_id'] ?? 0) === $accountId) {
                return trim((string)($r['name'] ?? ''));
            }
        }
        return '';
    }

    /**
     * Scan today's finance.getTransactions for a matching correction
     * we just attempted — same account / type / amount, comment that
     * starts with our COMMENT_BASE. Treats errors as "no match" so
     * we never block the operator on a transient Poster API hiccup.
     */
    private function alreadyExistsToday(int $type, int $accountId, string $sum, string $comment): bool
    {
        try {
            $today = str_replace('-', '', date('Y-m-d'));
            $rows  = $this->poster->client()->request('finance.getTransactions', [
                'dateFrom' => $today,
                'dateTo'   => $today,
                'timezone' => 'client',
            ]);
        } catch (\Throwable $e) {
            return false;
        }
        if (!is_array($rows)) return false;

        $needle = mb_strtolower(self::COMMENT_BASE, 'UTF-8');
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            if ((int)($r['type'] ?? -1) !== $type) continue;

            $extract = static function (mixed $v): int {
                if (is_array($v)) return (int)($v['account_id'] ?? $v['id'] ?? 0);
                return (int)$v;
            };
            $accTo   = $extract($r['account_to']   ?? $r['account_to_id']   ?? 0);
            $accFrom = $extract($r['account_from'] ?? $r['account_from_id'] ?? 0);
            if ($type === 1 && $accTo   !== $accountId) continue;
            if ($type === 0 && $accFrom !== $accountId) continue;

            $rawSum = $type === 1
                ? (string)($r['amount_to']   ?? $r['sum'] ?? $r['amount'] ?? '')
                : (string)($r['amount_from'] ?? $r['sum'] ?? $r['amount'] ?? '');
            $normSum = trim(str_replace(',', '.', str_replace(' ', '', $rawSum)));
            if ($normSum === '' || $normSum !== $sum) continue;

            $cmt = mb_strtolower((string)($r['comment'] ?? $r['description'] ?? ''), 'UTF-8');
            if ($cmt !== '' && mb_strpos($cmt, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function sessionStart(): void
    {
        \App\Infrastructure\Session::start();
    }
}
