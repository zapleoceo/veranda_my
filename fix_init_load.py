import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

old_init = """        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $type = (int)($r['type'] ?? 0);
            if ($type !== 1) continue;

            $dStr = (string)($r['date'] ?? '');
            $ts = $dStr !== '' ? strtotime($dStr) : false;
            if ($ts === false || $ts < $startTs || $ts > $endTs) continue;

            $accId = (int)($r['account_id'] ?? 0);
            if ($accId !== 8 && $accId !== 9) continue;"""

new_init = """        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $tRaw = (string)($r['type'] ?? '');
            $isTransfer = ($tRaw === '2');
            $isIn = ($tRaw === '1' || strtoupper($tRaw) === 'I' || strtolower($tRaw) === 'in');
            $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
            if (!$isTransfer && !$isIn && !$isOut) continue;

            $dStr = (string)($r['date'] ?? '');
            $ts = $dStr !== '' ? strtotime($dStr) : false;
            if ($ts === false || $ts < $startTs || $ts > $endTs) continue;

            if ($isTransfer || $isIn) {
                $accId = (int)($r['account_id'] ?? 0);
            } else {
                $accId = (int)($r['recipient_id'] ?? $r['account_to'] ?? $r['account_to_id'] ?? 0);
            }
            if ($accId !== 8 && $accId !== 9) continue;"""

content = content.replace(old_init, new_init)

old_row = """            $rowOut = [
                'transfer_id' => $transferId,
                'transaction_id' => $tid,
                'ts' => (int)$ts,
                'sum_minor' => abs($amountMinor),
                'comment' => $cmt,
                'user' => $userName,
            ];"""
new_row = """            $rowOut = [
                'transfer_id' => $transferId,
                'transaction_id' => $tid,
                'ts' => (int)$ts,
                'sum_minor' => abs($amountMinor),
                'comment' => $cmt,
                'user' => $userName,
                'type' => $tRaw,
            ];"""
content = content.replace(old_row, new_row)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)
