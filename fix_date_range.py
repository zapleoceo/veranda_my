import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Fix line 773: $startTs = strtotime($dateTo . ' 00:00:00');
content = content.replace("$startTs = strtotime($dateTo . ' 00:00:00');", "$startTs = strtotime($dateFrom . ' 00:00:00');")

# 2. Fix line 933: $startTs = strtotime($dTo . ' 00:00:00');
content = content.replace("$startTs = strtotime($dTo . ' 00:00:00');", "$startTs = strtotime($dFrom . ' 00:00:00');")

# 3. Fix delete_finance_transfer to use $dFrom
# Around line 1134:
#     $dTo = trim((string)($payload['dateTo'] ?? ''));
#     if (!in_array($kind, ['vietnam', 'tips'], true) || ($transferId <= 0 && $txId <= 0) || $dTo === '') {
#         ...
#     $startTs = strtotime($dTo . ' 00:00:00');
old_delete_php = """    $dTo = trim((string)($payload['dateTo'] ?? ''));
    if (!in_array($kind, ['vietnam', 'tips'], true) || ($transferId <= 0 && $txId <= 0) || $dTo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $startTs = strtotime($dTo . ' 00:00:00');
    $endTs = strtotime($dTo . ' 23:59:59');"""
new_delete_php = """    $dTo = trim((string)($payload['dateTo'] ?? ''));
    $dFrom = trim((string)($payload['dateFrom'] ?? $dTo));
    if (!in_array($kind, ['vietnam', 'tips'], true) || ($transferId <= 0 && $txId <= 0) || $dTo === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Bad request'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $startTs = strtotime($dFrom . ' 00:00:00');
    $endTs = strtotime($dTo . ' 23:59:59');"""
content = content.replace(old_delete_php, new_delete_php)

# 4. Fix line 2751: $startTs = strtotime($dateTo . ' 00:00:00');
# Already handled by step 1 if the exact string matches, but let's check
# It is EXACTLY: $startTs = strtotime($dateTo . ' 00:00:00');
# Wait, step 1 replaced all of them! Let's ensure it.

# 5. Fix date format in finance.getTransactions
# replace date('dmY', $startTs) with date('Ymd', $startTs)
# replace date('dmY', $endTs) with date('Ymd', $endTs)
# replace date('dmY', $startTs !== false ? $startTs : time()) with date('Ymd', $startTs !== false ? $startTs : time())
# replace date('dmY', $endTs !== false ? $endTs : time()) with date('Ymd', $endTs !== false ? $endTs : time())
content = content.replace("date('dmY',", "date('Ymd',")

# 6. Fix JS for delete_finance_transfer to pass dateFrom
old_js = """            const kind = String(btn.getAttribute('data-kind') || '');
            if ((!transferId && !txId) || !dateTo || !kind) return;
            const comment = prompt('Комментарий (почему скрываем):', '');
            if (comment === null) return;
            const c = String(comment || '').trim();
            fetch('?ajax=delete_finance_transfer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind, transfer_id: transferId, tx_id: txId, dateTo, comment: c }),
            })"""
new_js = """            const kind = String(btn.getAttribute('data-kind') || '');
            const dateFrom = String(btn.getAttribute('data-date-from') || dateTo);
            if ((!transferId && !txId) || !dateTo || !kind) return;
            const comment = prompt('Комментарий (почему скрываем):', '');
            if (comment === null) return;
            const c = String(comment || '').trim();
            fetch('?ajax=delete_finance_transfer', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind, transfer_id: transferId, tx_id: txId, dateFrom, dateTo, comment: c }),
            })"""
content = content.replace(old_js, new_js)

# Also need to add data-date-from to the delete button rendering
old_html_del_vietnam = """<div><button type="button" class="finance-del" data-kind="vietnam" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button><?= htmlspecialchars($line) ?></div>"""
new_html_del_vietnam = """<div><button type="button" class="finance-del" data-kind="vietnam" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-from="<?= htmlspecialchars($dateFrom) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button><?= htmlspecialchars($line) ?></div>"""
content = content.replace(old_html_del_vietnam, new_html_del_vietnam)

old_html_del_tips = """<div><button type="button" class="finance-del" data-kind="tips" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button><?= htmlspecialchars($line) ?></div>"""
new_html_del_tips = """<div><button type="button" class="finance-del" data-kind="tips" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-from="<?= htmlspecialchars($dateFrom) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button><?= htmlspecialchars($line) ?></div>"""
content = content.replace(old_html_del_tips, new_html_del_tips)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Done")
