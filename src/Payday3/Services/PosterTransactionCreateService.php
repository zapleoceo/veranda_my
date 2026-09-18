<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\AuditLogInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\NamedLockInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterTransactionCreateServiceInterface;
use App\Payday3\Domain\Actor;

/**
 * Calls Poster's finance.createTransactions for the "+" popup that
 * the operator opens from an OUT-mail row.
 *
 * Direct port of payday2's `?ajax=create_poster_transaction` — same
 * UI semantics (1 = income / 2 = expense / 3 = transfer) translated
 * to the Poster wire format (1=income, 0=expense, 2=transfer).
 *
 * Amount is sent as a plain integer (VND, no cents). Poster
 * accepts that.
 *
 * Guards (security audit):
 *   - accounts must be among those configured in LocalSettings;
 *   - 0 < amount ≤ MAX_AMOUNT_VND;
 *   - date must be 'Y-m-d H:i:s' (or 'Y-m-d H:i');
 *   - an identical request (type, accounts, amount, comment) from the
 *     same session within IDEMPOTENCY_WINDOW_S is rejected (double-submit);
 *   - every created transaction leaves an audit row (payday_audit_log).
 */
final class PosterTransactionCreateService implements PosterTransactionCreateServiceInterface
{
    public const MAX_AMOUNT_VND       = 1_000_000_000;
    public const IDEMPOTENCY_WINDOW_S = 10;

    public function __construct(
        private readonly PosterApiProviderInterface       $poster,
        private readonly LocalSettingsRepositoryInterface $settings,
        private readonly AuditLogInterface                $audit,
        private readonly NamedLockInterface               $lock,
    ) {}

    public function create(array $input, ?Actor $actor = null): array
    {
        $actor       ??= new Actor('');
        $type        = (int)($input['type']         ?? 0);
        $amount      = (int)($input['amount']       ?? 0);
        $date        = trim((string)($input['date'] ?? ''));
        $comment     = trim((string)($input['comment']     ?? ''));
        $categoryId  = (int)($input['category_id']  ?? 0);
        $accountFrom = (int)($input['account_from'] ?? 0);
        $accountTo   = (int)($input['account_to']   ?? 0);

        if ($type < 1 || $type > 3)                    throw new \InvalidArgumentException('Invalid type');
        if ($amount <= 0)                              throw new \InvalidArgumentException('Invalid amount');
        if ($amount > self::MAX_AMOUNT_VND) {
            throw new \InvalidArgumentException('Сумма больше лимита ' . number_format(self::MAX_AMOUNT_VND, 0, '.', ' ') . ' VND');
        }
        if (!self::validDate($date))                   throw new \InvalidArgumentException('Invalid date (ожидается YYYY-MM-DD HH:MM:SS)');

        $payload = [
            'type'    => $type === 1 ? 1 : ($type === 2 ? 0 : 2), // UI → Poster wire
            'date'    => $date,
            'comment' => $comment,
            // The form sends a wall-clock string ('Y-m-d H:i:s') in the
            // operator's local timezone, which we declare as the account's
            // configured timezone (Vietnam) via `timezone=client`. Without
            // this Poster interprets the string in its server timezone
            // (~Moscow), shifting everything by 3–4 hours.
            'timezone' => 'client',
        ];
        if ($categoryId > 0) {
            $payload['category'] = $categoryId;
        }

        if ($type === 1) {                              // income
            if ($accountTo <= 0) throw new \InvalidArgumentException('Не выбран Account To');
            $payload['account_to'] = $accountTo;
            $payload['amount_to']  = $amount;
        } elseif ($type === 2) {                        // expense
            if ($accountFrom <= 0) throw new \InvalidArgumentException('Не выбран Account From');
            $payload['account_from'] = $accountFrom;
            $payload['amount_from']  = $amount;
        } else {                                        // transfer (3)
            if ($accountFrom <= 0 || $accountTo <= 0) throw new \InvalidArgumentException('Не выбраны оба счёта');
            if ($accountFrom === $accountTo)          throw new \InvalidArgumentException('Счета From и To должны различаться');
            $payload['account_from'] = $accountFrom;
            $payload['amount_from']  = $amount;
            $payload['account_to']   = $accountTo;
            $payload['amount_to']    = $amount;
        }

        $allowed = $this->settings->load()->configuredAccountIds();
        foreach ([$payload['account_from'] ?? null, $payload['account_to'] ?? null] as $acc) {
            if ($acc !== null && !in_array($acc, $allowed, true)) {
                throw new \InvalidArgumentException('Счёт ' . $acc . ' не входит в настроенные счета payday (⚙ Настройки).');
            }
        }

        $fingerprint = hash('sha256', implode('|', [
            $actor->sessionKey, $type, $accountFrom, $accountTo, $amount, $comment,
        ]));

        // Check + create + audit under one lock, so two parallel
        // double-clicks can't both see "no recent twin".
        return $this->lock->synchronized('payday_tx_' . $fingerprint, 5, function () use ($payload, $fingerprint, $actor, $input) {
            if ($this->audit->existsRecent($fingerprint, self::IDEMPOTENCY_WINDOW_S)) {
                throw new \DomainException('Такая же транзакция только что создана — повтор отклонён. Проверьте список в Poster.');
            }
            try {
                $resp = $this->poster->client()->request('finance.createTransactions', $payload, 'POST');
            } catch (\Throwable $e) {
                throw new \RuntimeException('Poster: ' . $e->getMessage(), 0, $e);
            }
            try {
                $this->audit->record($actor->email, 'poster_tx.create', [
                    'request'  => $payload,
                    'ui_type'  => (int)($input['type'] ?? 0),
                    'response' => $resp,
                ], $fingerprint);
            } catch (\Throwable $e) {
                // The Poster transaction exists — don't report failure for it.
                error_log('[payday3.poster_tx] audit write failed: ' . $e->getMessage());
            }
            return ['ok' => true, 'response' => $resp];
        });
    }

    private static function validDate(string $date): bool
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $date);
            if ($d !== false && $d->format($fmt) === $date) return true;
        }
        return false;
    }
}
