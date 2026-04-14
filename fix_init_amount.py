import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

old_amount = """            $amountMinor = (int)($r['amount'] ?? 0);
            if ($amountMinor <= 0) continue;"""

new_amount = """            $amountMinor = (int)($r['amount'] ?? 0);
            if (abs($amountMinor) <= 0) continue;"""

content = content.replace(old_amount, new_amount)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)
