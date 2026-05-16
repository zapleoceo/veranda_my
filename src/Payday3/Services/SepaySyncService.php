<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Payday3\Contracts\SepaySyncServiceInterface;
use App\Payday3\Domain\DateRange;

/**
 * Cleaned-up port of payday2/post/reload_sepay_api.php (145 → ~100 lines).
 *
 * Hits SePay's REST API at /userapi/transactions/list and writes every
 * row to sepay_transactions (ON DUPLICATE KEY UPDATE). The payment
 * method is inferred from content/sub_account heuristics — same logic
 * as payday2: 'bybit' → Bybit, 'vietnam company' → Vietnam Company,
 * otherwise Card.
 *
 * Requires .env keys SEPAY_API_TOKEN and (optionally)
 * SEPAY_ACCOUNT_NUMBER for filtering.
 */
final class SepaySyncService implements SepaySyncServiceInterface
{
    public function __construct(private readonly Database $db) {}

    public function sync(DateRange $range): array
    {
        $token   = trim((string)($_ENV['SEPAY_API_TOKEN']      ?? Config::get('SEPAY_API_TOKEN')));
        $account = trim((string)($_ENV['SEPAY_ACCOUNT_NUMBER'] ?? Config::get('SEPAY_ACCOUNT_NUMBER')));
        if ($token === '') {
            throw new \RuntimeException('SEPAY_API_TOKEN is not configured');
        }

        $txs = $this->fetchTransactions($range, $token, $account);
        $st  = $this->db->t('sepay_transactions');

        $upsert = $this->db->getPdo()->prepare(
            "INSERT INTO {$st}
                (sepay_id, gateway, transaction_date, account_number, code, content,
                 transfer_type, transfer_amount, accumulated, sub_account, reference_code,
                 description, payment_method, raw_request_body)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                gateway = VALUES(gateway), transaction_date = VALUES(transaction_date),
                account_number = VALUES(account_number), code = VALUES(code),
                content = VALUES(content), transfer_type = VALUES(transfer_type),
                transfer_amount = VALUES(transfer_amount), accumulated = VALUES(accumulated),
                sub_account = VALUES(sub_account), reference_code = VALUES(reference_code),
                description = VALUES(description), payment_method = VALUES(payment_method),
                raw_request_body = VALUES(raw_request_body),
                was_deleted = 0, deleted_at = NULL"
        );

        $inserted = 0; $updated = 0; $skipped = 0;
        foreach ($txs as $tx) {
            if (!is_array($tx)) { $skipped++; continue; }
            $sepayId = (int)($tx['id'] ?? 0);
            if ($sepayId <= 0) { $skipped++; continue; }

            $ts = strtotime((string)($tx['transaction_date'] ?? $tx['transactionDate'] ?? ''));
            if ($ts === false || $ts <= 0) { $skipped++; continue; }

            // amount_in / amount_out → transfer_type + amount
            $in  = (float)($tx['amount_in']  ?? 0);
            $out = (float)($tx['amount_out'] ?? 0);
            if ($out > 0.0001 && $in <= 0.0001) { $type = 'out'; $amount = (int)round($out); }
            else                                 { $type = 'in';  $amount = (int)round($in);  }

            $content   = trim((string)($tx['transaction_content'] ?? $tx['content'] ?? ''));
            $sub       = self::nullableString($tx['sub_account'] ?? $tx['subAccount'] ?? null);
            $code      = self::nullableString($tx['code'] ?? null);
            $reference = trim((string)($tx['reference_number'] ?? $tx['referenceCode'] ?? $tx['reference_code'] ?? ''));
            $gateway   = trim((string)($tx['bank_brand_name'] ?? $tx['gateway'] ?? '')) ?: 'Unknown';
            $accNo     = trim((string)($tx['account_number'] ?? $tx['accountNumber'] ?? '')) ?: 'Unknown';
            $accum     = self::moneyToInt($tx['accumulated'] ?? 0);
            $method    = self::inferMethod($content . ' ' . (string)$sub);
            $raw       = json_encode($tx, JSON_UNESCAPED_UNICODE) ?: null;

            $stmt = $upsert;
            $stmt->execute([
                $sepayId, $gateway, date('Y-m-d H:i:s', $ts), $accNo, $code,
                $content !== '' ? $content : '-',
                $type, $amount, $accum, $sub,
                $reference !== '' ? $reference : '-',
                $content !== '' ? $content : '-',
                $method, $raw,
            ]);
            $affected = $stmt->rowCount();
            if      ($affected === 1) $inserted++;
            else if ($affected >= 2)  $updated++;
        }
        return [
            'inserted' => $inserted,
            'updated'  => $updated,
            'skipped'  => $skipped,
            'apiRows'  => count($txs),
        ];
    }

    private function fetchTransactions(DateRange $range, string $token, string $accountNumber): array
    {
        $params = [
            'transaction_date_min' => $range->from . ' 00:00:00',
            'transaction_date_max' => $range->to   . ' 23:59:59',
            'limit'                => 5000,
        ];
        if ($accountNumber !== '') $params['account_number'] = $accountNumber;
        $url = 'https://my.sepay.vn/userapi/transactions/list?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('SePay API: curl_init failed');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT,        20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) throw new \RuntimeException('SePay API: ' . ($err ?: 'request failed'));
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded))         throw new \RuntimeException('SePay API: invalid JSON (http=' . $http . ')');
        if ($http < 200 || $http > 299)  throw new \RuntimeException('SePay API: http=' . $http);

        return is_array($decoded['transactions'] ?? null) ? $decoded['transactions'] : [];
    }

    private static function inferMethod(string $haystack): string
    {
        $h = strtolower($haystack);
        if (str_contains($h, 'bybit'))           return 'Bybit';
        if (str_contains($h, 'vietnam company')) return 'Vietnam Company';
        return 'Card';
    }

    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private static function moneyToInt(mixed $v): int
    {
        if (is_int($v))   return $v;
        if (is_float($v)) return (int)round($v);
        if (is_string($v) && is_numeric($v)) return (int)round((float)$v);
        return 0;
    }
}
