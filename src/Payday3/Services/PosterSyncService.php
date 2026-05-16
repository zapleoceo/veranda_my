<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Classes\PosterAPI;
use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Payday3\Contracts\PosterSyncServiceInterface;
use App\Payday3\Domain\DateRange;

/**
 * Cleaned-up port of payday2/post/load_poster_checks.php (265 → 150 lines).
 *
 * Drops:
 *   - the progress-streaming protocol (synchronous JSON response now)
 *   - the inline batched-SQL bookkeeping (PDO prepared statement
 *     executed per row reads as well; batching is a micro-optimisation
 *     for hundreds of rows per call, which we rarely hit)
 *   - the duplicated waiter-name lookup path
 *
 * Keeps the contract: hit settings.getPaymentMethods twice
 * (money_type=2 payment_type=2 and =7), upsert poster_payment_methods,
 * fetch dash.getTransactions for the range, upsert poster_checks for
 * rows with pay_type in {2,3} that have card / third-party / tips.
 */
final class PosterSyncService implements PosterSyncServiceInterface
{
    public function __construct(private readonly Database $db) {}

    public function sync(DateRange $range): array
    {
        $token = (string)($_ENV['POSTER_API_TOKEN'] ?? Config::get('POSTER_API_TOKEN'));
        if ($token === '') {
            throw new \RuntimeException('POSTER_API_TOKEN is not configured');
        }
        $api = new PosterAPI($token);

        $methodsCount = $this->syncPaymentMethods($api);

        $ymdFrom = str_replace('-', '', $range->from);
        $ymdTo   = str_replace('-', '', $range->to);

        $txs = [];
        try {
            $txs = $api->request('dash.getTransactions', [
                'dateFrom'         => $ymdFrom,
                'dateTo'           => $ymdTo,
                'status'           => 2,
                'include_products' => 0,
                'include_history'  => 0,
            ]);
        } catch (\Throwable $e) {
            // Surface as a domain error; controller turns it into a 500.
            throw new \RuntimeException('dash.getTransactions failed: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($txs)) $txs = [];

        $pc = $this->db->t('poster_checks');
        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        $upsert = $this->db->getPdo()->prepare(
            "INSERT INTO {$pc} (transaction_id, receipt_number, table_id, spot_id, sum, payed_sum,
                                payed_cash, payed_card, payed_cert, payed_bonus, payed_third_party,
                                pay_type, reason, tip_sum, discount, date_close,
                                poster_payment_method_id, waiter_name, day_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                receipt_number = VALUES(receipt_number), table_id = VALUES(table_id),
                spot_id = VALUES(spot_id), sum = VALUES(sum), payed_sum = VALUES(payed_sum),
                payed_cash = VALUES(payed_cash), payed_card = VALUES(payed_card),
                payed_cert = VALUES(payed_cert), payed_bonus = VALUES(payed_bonus),
                payed_third_party = VALUES(payed_third_party), pay_type = VALUES(pay_type),
                reason = VALUES(reason), tip_sum = VALUES(tip_sum), discount = VALUES(discount),
                date_close = VALUES(date_close),
                poster_payment_method_id = VALUES(poster_payment_method_id),
                waiter_name = VALUES(waiter_name), day_date = VALUES(day_date),
                was_deleted = 0, deleted_at = NULL"
        );

        $exists = $this->db->getPdo()->prepare(
            "SELECT 1 FROM {$pc} WHERE transaction_id = ? LIMIT 1"
        );

        foreach ($txs as $tx) {
            if (!is_array($tx)) { $skipped++; continue; }

            $txId    = (int)($tx['transaction_id'] ?? $tx['id'] ?? 0);
            $payType = (int)($tx['pay_type'] ?? $tx['payType'] ?? 0);
            if ($txId <= 0 || ($payType !== 2 && $payType !== 3)) { $skipped++; continue; }

            $closeAt = self::parseDateTime($tx);
            if ($closeAt === null) { $skipped++; continue; }

            $payedCard        = self::moneyToInt($tx['payed_card']        ?? $tx['payedCard']       ?? 0);
            $payedThirdParty  = self::moneyToInt($tx['payed_third_party'] ?? $tx['payedThirdParty'] ?? 0);
            $serviceTip       = self::moneyToInt($tx['tip_sum'] ?? $tx['tipSum'] ?? 0);
            $tipsCard         = self::moneyToInt($tx['tips_card'] ?? $tx['tipsCard'] ?? 0);
            $tipsCash         = self::moneyToInt($tx['tips_cash'] ?? $tx['tipsCash'] ?? 0);
            $tipSum           = $serviceTip + $tipsCard + $tipsCash;
            if (($payedCard + $payedThirdParty + $tipSum) <= 0) { $skipped++; continue; }

            $dayDate     = substr($closeAt, 0, 10);
            $waiterName  = trim((string)($tx['waiter_name'] ?? $tx['name'] ?? ''));
            $receiptNum  = (int)($tx['receipt_number'] ?? $tx['receiptNumber'] ?? $txId);
            $tableId     = isset($tx['table_id']) ? (int)$tx['table_id'] : null;
            $spotId      = isset($tx['spot_id'])  ? (int)$tx['spot_id']  : null;
            $sum         = self::moneyToInt($tx['sum'] ?? 0);
            $payedSum    = self::moneyToInt($tx['payed_sum']   ?? $tx['payedSum']  ?? 0);
            $payedCash   = self::moneyToInt($tx['payed_cash']  ?? $tx['payedCash'] ?? 0);
            $payedCert   = self::moneyToInt($tx['payed_cert']  ?? $tx['payedCert'] ?? 0);
            $payedBonus  = self::moneyToInt($tx['payed_bonus'] ?? $tx['payedBonus']?? 0);
            $discount    = (float)($tx['discount'] ?? 0);
            $reason      = isset($tx['reason']) ? (int)$tx['reason'] : null;
            $pmId        = (int)($tx['payment_method_id'] ?? $tx['paymentMethodId'] ?? 0);

            $exists->execute([$txId]);
            $isUpdate = (bool)$exists->fetchColumn();
            $exists->closeCursor();

            $upsert->execute([
                $txId, $receiptNum ?: null, $tableId, $spotId, $sum, $payedSum,
                $payedCash, $payedCard, $payedCert, $payedBonus, $payedThirdParty,
                $payType, $reason, $tipSum, $discount, $closeAt,
                $pmId > 0 ? $pmId : null,
                $waiterName !== '' ? $waiterName : null, $dayDate,
            ]);
            $isUpdate ? $updated++ : $inserted++;
        }

        return [
            'inserted' => $inserted,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'methods'  => $methodsCount,
        ];
    }

    /** Pull payment-method metadata once and upsert poster_payment_methods. */
    private function syncPaymentMethods(PosterAPI $api): int
    {
        $ppm = $this->db->t('poster_payment_methods');
        $methods = [];
        foreach ([2, 7] as $paymentType) {
            try {
                $batch = $api->request('settings.getPaymentMethods', [
                    'money_type'   => 2,
                    'payment_type' => $paymentType,
                ]);
                if (is_array($batch)) $methods = array_merge($methods, $batch);
            } catch (\Throwable $e) {
                // Continue with whatever we have; some envs return one type only.
            }
        }
        $upsert = $this->db->getPdo()->prepare(
            "INSERT INTO {$ppm} (payment_method_id, title, color, money_type, payment_type, is_active)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                title = VALUES(title), color = VALUES(color),
                money_type = VALUES(money_type), payment_type = VALUES(payment_type),
                is_active = VALUES(is_active)"
        );
        $count = 0;
        foreach ($methods as $m) {
            if (!is_array($m)) continue;
            $id    = (int)($m['payment_method_id'] ?? $m['paymentMethodId'] ?? 0);
            $title = trim((string)($m['title'] ?? ''));
            if ($id <= 0 || $title === '') continue;
            $upsert->execute([
                $id, $title,
                ($m['color'] ?? null) !== null ? (string)$m['color'] : null,
                (int)($m['money_type']   ?? $m['moneyType']   ?? 0),
                (int)($m['payment_type'] ?? $m['paymentType'] ?? 0),
                (int)($m['is_active']    ?? $m['isActive']    ?? 1),
            ]);
            $count++;
        }
        return $count;
    }

    private static function moneyToInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_float($v)) return (int)round($v);
        if (is_string($v) && is_numeric($v)) return (int)round((float)$v);
        return 0;
    }

    private static function parseDateTime(array $tx): ?string
    {
        $candidates = ['date_close', 'date_close_date', 'dateClose'];
        foreach ($candidates as $key) {
            $v = $tx[$key] ?? null;
            if ($v === null || $v === '') continue;
            if (is_numeric($v)) {
                $n = (int)$v;
                if ($n > 20_000_000_000) $n = (int)round($n / 1000);
                if ($n > 0) return date('Y-m-d H:i:s', $n);
            }
            if (is_string($v)) {
                $t = strtotime($v);
                if ($t !== false && $t > 0 && (int)date('Y', $t) >= 2000) {
                    return date('Y-m-d H:i:s', $t);
                }
            }
        }
        return null;
    }
}
