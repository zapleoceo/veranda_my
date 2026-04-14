import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

old_out = """            $txId = (int)($row['transaction_id'] ?? $row['id'] ?? 0);
            $out[] = [
                'transaction_id' => $txId,
                'transfer_id' => $txId,
                'ts' => (int)$ts,
                'sum' => (int)$sum,
                'comment' => $cmt,
                'user' => $userName,
            ];"""
new_out = """            $txId = (int)($row['transaction_id'] ?? $row['id'] ?? 0);
            $out[] = [
                'transaction_id' => $txId,
                'transfer_id' => $txId,
                'ts' => (int)$ts,
                'sum' => (int)$sum,
                'comment' => $cmt,
                'user' => $userName,
                'type' => $tRaw,
            ];"""

content = content.replace(old_out, new_out)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)
