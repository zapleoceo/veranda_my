import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Fix create_transfer (POST) around line 805
old_post = """        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $type = (int)($row['type'] ?? 0);
            if ($type !== 2) continue;
            $toRaw = $row['account_to_id'] ?? $row['account_to'] ?? $row['accountToId'] ?? $row['accountTo'] ?? 0;"""
new_post = """        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $tRaw = (string)($row['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isOut) continue;
            if ($isTransfer) {
                $toRaw = $row['account_to_id'] ?? $row['account_to'] ?? $row['accountToId'] ?? $row['accountTo'] ?? 0;
            } else {
                $toRaw = $row['recipient_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0;
            }"""
content = content.replace(old_post, new_post)

# 2. Fix create_transfer (AJAX) around line 947 -> 973
old_ajax = """        $found = null;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $type = (int)($row['type'] ?? 0);
            if ($type !== 0 && $type !== 1) continue;"""
new_ajax = """        $found = null;
        foreach ($txs as $row) {
            if (!is_array($row)) continue;
            $tRaw = (string)($row['type'] ?? '');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            if (!$isOut && !$isIn) continue;
            $type = $isOut ? 0 : 1;"""
content = content.replace(old_ajax, new_ajax)

# 2.5 Fix create_transfer (AJAX) account_to check for type O
old_ajax_acc = """            $accRaw = $row['account_id'] ?? $row['accountId'] ?? $row['account_from_id'] ?? $row['account_from'] ?? $row['accountFromId'] ?? $row['accountFrom'] ?? 0;
            if (is_array($accRaw)) $accRaw = $accRaw['account_id'] ?? $accRaw['id'] ?? 0;
            $accId = (int)$accRaw;

            $sumRaw = $row['amount_from'] ?? $row['amountFrom'] ?? $row['amount_to'] ?? $row['amountTo'] ?? $row['sum'] ?? $row['amount'] ?? 0;
            $sumMaybe = $normMoney($sumRaw);
            if (abs($sumMaybe) !== $amountVnd) continue;

            $cmt = (string)($row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '');
            if ($normText($cmt !== '' ? $cmt : $comment) !== $normText($comment)) continue;

            $isMatch = false;
            if ($type === 0 && $sumMaybe < 0 && $accId === 1) $isMatch = true;
            if ($type === 1 && $sumMaybe > 0 && $accId === $accountTo) $isMatch = true;
            if (!$isMatch) continue;"""
new_ajax_acc = """            $accRaw = $row['account_id'] ?? $row['accountId'] ?? $row['account_from_id'] ?? $row['account_from'] ?? $row['accountFromId'] ?? $row['accountFrom'] ?? 0;
            if (is_array($accRaw)) $accRaw = $accRaw['account_id'] ?? $accRaw['id'] ?? 0;
            $accId = (int)$accRaw;
            
            $toRaw = $row['recipient_id'] ?? $row['account_to_id'] ?? $row['account_to'] ?? 0;
            if (is_array($toRaw)) $toRaw = $toRaw['account_id'] ?? $toRaw['id'] ?? 0;
            $toId = (int)$toRaw;

            $sumRaw = $row['amount_from'] ?? $row['amountFrom'] ?? $row['amount_to'] ?? $row['amountTo'] ?? $row['sum'] ?? $row['amount'] ?? 0;
            $sumMaybe = $normMoney($sumRaw);
            if (abs($sumMaybe) !== $amountVnd) continue;

            $cmt = (string)($row['comment'] ?? $row['description'] ?? $row['comment_text'] ?? '');
            if ($normText($cmt !== '' ? $cmt : $comment) !== $normText($comment)) continue;

            $isMatch = false;
            // Support both sumMaybe < 0 and sumMaybe > 0 for type 0, because sometimes API returns absolute value
            if ($type === 0 && $accId === 1 && $toId === $accountTo) $isMatch = true;
            if ($type === 1 && $sumMaybe > 0 && $accId === $accountTo) $isMatch = true;
            if (!$isMatch) continue;"""
content = content.replace(old_ajax_acc, new_ajax_acc)


# 3. Fix refresh_finance_transfers around line 1115
old_refresh = """        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (((int)($row['status'] ?? 0)) === 3) continue;
            $type = (int)($row['type'] ?? -1);
            if ($type !== 2) continue;"""
new_refresh = """        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (((int)($row['status'] ?? 0)) === 3) continue;
            $tRaw = (string)($row['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isOut) continue;"""
content = content.replace(old_refresh, new_refresh)

# 4. Fix refresh_finance_transfers account checks around line 1134
old_refresh_acc = """            $accFromRaw = $row['account_from'] ?? $row['account_from_id'] ?? $row['account_id'] ?? 0;
            if (is_array($accFromRaw)) $accFromRaw = $accFromRaw['account_id'] ?? $accFromRaw['id'] ?? 0;
            $accFromId = (int)$accFromRaw;

            $accToRaw = $row['account_to'] ?? $row['account_to_id'] ?? 0;
            if (is_array($accToRaw)) $accToRaw = $accToRaw['account_id'] ?? $accToRaw['id'] ?? 0;
            $accToId = (int)$accToRaw;"""
new_refresh_acc = """            $accFromRaw = $row['account_from'] ?? $row['account_from_id'] ?? $row['account_id'] ?? 0;
            if (is_array($accFromRaw)) $accFromRaw = $accFromRaw['account_id'] ?? $accFromRaw['id'] ?? 0;
            $accFromId = (int)$accFromRaw;

            if ($isTransfer) {
                $accToRaw = $row['account_to'] ?? $row['account_to_id'] ?? 0;
            } else {
                $accToRaw = $row['recipient_id'] ?? $row['account_to'] ?? $row['account_to_id'] ?? 0;
            }
            if (is_array($accToRaw)) $accToRaw = $accToRaw['account_id'] ?? $accToRaw['id'] ?? 0;
            $accToId = (int)$accToRaw;"""
content = content.replace(old_refresh_acc, new_refresh_acc)

# 5. Fix dates in refresh_finance_transfers around 1097
old_refresh_date = """            $rows = $api->request('finance.getTransactions', [
                'dateFrom' => date('Ymd', $startTs),
                'dateTo' => date('Ymd', $endTs),
            ]);"""
new_refresh_date = """            $rows = $api->request('finance.getTransactions', [
                'dateFrom' => date('dmY', $startTs),
                'dateTo' => date('dmY', $endTs),
            ]);"""
content = content.replace(old_refresh_date, new_refresh_date)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Done")
