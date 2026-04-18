<?php
$rows = $api->request('finance.getAccounts', []);
        if (!is_array($rows)) $rows = [];
        try {
            $db->query("DELETE FROM {$pa}");
        } catch (\Throwable $e) {
        }
        $upserted = 0;
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $accountId = (int)($r['account_id'] ?? $r['accountId'] ?? 0);
            $name = trim((string)($r['name'] ?? ''));
            if ($accountId <= 0 || $name === '') continue;
            $upserted += (int)$db->query(
                "INSERT INTO {$pa} (account_id, name, type, currency_id, currency_symbol, currency_code_iso, currency_code, balance, balance_start, percent_acquiring)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    type = VALUES(type),
                    currency_id = VALUES(currency_id),
                    currency_symbol = VALUES(currency_symbol),
                    currency_code_iso = VALUES(currency_code_iso),
                    currency_code = VALUES(currency_code),
                    balance = VALUES(balance),
                    balance_start = VALUES(balance_start),
                    percent_acquiring = VALUES(percent_acquiring)",
                [
                    $accountId,
                    $name,
                    (int)($r['type'] ?? 0),
                    isset($r['currency_id']) ? (int)$r['currency_id'] : null,
                    isset($r['currency_symbol']) ? (string)$r['currency_symbol'] : null,
                    isset($r['currency_code_iso']) ? (string)$r['currency_code_iso'] : null,
                    isset($r['currency_code']) ? (string)$r['currency_code'] : null,
                    $moneyToInt($r['balance'] ?? 0),
                    isset($r['balance_start']) ? $moneyToInt($r['balance_start']) : null,
                    isset($r['percent_acquiring']) ? (float)$r['percent_acquiring'] : null,
                ]
            )->rowCount();
        }
        $message = 'Баланс Poster обновлён: ' . $upserted;
