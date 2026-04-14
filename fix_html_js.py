import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Block Title and button
old_title = """        <div class="card card-finance">
            <div style="font-weight: 900; margin-bottom: 10px;">Финансовые транзакции</div>"""
new_title = """        <div class="card card-finance">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">
                <div style="font-weight: 900;">Финансовые транзакции</div>
                <button class="btn tiny" id="finance-refresh-all" type="button" title="Обновить">🔄</button>
            </div>"""
content = content.replace(old_title, new_title)

# 2. Remove individual refresh buttons
content = content.replace('<button class="btn tiny finance-refresh" type="button" title="Обновить">🔄</button>\n', '')

# 3. Vietnam Table Render
old_vietnam_status = """                        <?php if ($transferVietnamExists): ?>
                            <span style="color:#81c784; font-weight:900;">Найдены транзакции за день:</span>
                            <?php foreach ($transferVietnamFoundList as $f): ?>
                                <?php
                                    $ts = (int)($f['ts'] ?? 0);
                                    $sumMinor = (int)($f['sum_minor'] ?? 0);
                                    $cmt = trim((string)($f['comment'] ?? ''));
                                    $u = trim((string)($f['user'] ?? ''));
                                    $bid = (int)($f['binding_id'] ?? 0);
                                    $sumVnd = $posterCentsToVnd($sumMinor);
                                    $line = date('d.m.Y', $ts) . ' - ' . date('H:i:s', $ts) . ' - ' . $fmtVnd($sumVnd);
                                    if ($u !== '') $line .= ' - ' . $u;
                                    if ($cmt !== '') $line .= ' - ' . $cmt;
                                ?>
                                <div><button type="button" class="finance-del" data-kind="vietnam" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        <?php elseif ($vietnamDisabled): ?>"""

new_vietnam_status = """                        <?php if ($transferVietnamExists): ?>
                            <table class="table" style="margin-top:5px; font-size:12px;">
                                <thead><tr><th>Дата</th><th>Время</th><th>Сумма совпадает</th><th>Тип</th><th>Комментарий</th><th></th></tr></thead>
                                <tbody>
                            <?php foreach ($transferVietnamFoundList as $f): ?>
                                <?php
                                    $ts = (int)($f['ts'] ?? 0);
                                    $sumMinor = (int)($f['sum_minor'] ?? 0);
                                    $cmt = trim((string)($f['comment'] ?? ''));
                                    $u = trim((string)($f['user'] ?? ''));
                                    $bid = (int)($f['binding_id'] ?? 0);
                                    $sumVnd = $posterCentsToVnd($sumMinor);
                                    
                                    $sumMatch = ($sumVnd === (int)($vietnamVnd ?? 0));
                                    $sumIcon = $sumMatch ? '<span style="color:#81c784; font-weight:900;">✓</span>' : '<span style="color:#e57373; font-weight:900;">✕</span>';
                                    
                                    $tRaw = (string)($f['type'] ?? '');
                                    $typeText = ($tRaw === '2') ? 'Перевод' : (($tRaw === '0' || strtolower($tRaw) === 'o' || strtolower($tRaw) === 'out') ? 'Расход' : (($tRaw === '1' || strtolower($tRaw) === 'i' || strtolower($tRaw) === 'in') ? 'Приход' : $tRaw));
                                    
                                    $commentText = $u !== '' ? "$cmt ($u)" : $cmt;
                                    $dateStr = date('d.m.Y', $ts);
                                    $timeStr = date('H:i:s', $ts);
                                    $sumText = $sumIcon . ' ' . $fmtVnd($sumVnd);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($dateStr) ?></td>
                                    <td><?= htmlspecialchars($timeStr) ?></td>
                                    <td><?= $sumText ?></td>
                                    <td><?= htmlspecialchars($typeText) ?></td>
                                    <td><?= htmlspecialchars($commentText) ?></td>
                                    <td style="text-align:right;"><button type="button" class="finance-del btn tiny" style="padding:0 4px;" data-kind="vietnam" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button></td>
                                </tr>
                            <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php elseif ($vietnamDisabled): ?>"""
content = content.replace(old_vietnam_status, new_vietnam_status)

# 4. Tips Table Render
old_tips_status = """                        <?php if ($transferTipsExists): ?>
                            <span style="color:#81c784; font-weight:900;">Найдены транзакции за день:</span>
                            <?php foreach ($transferTipsFoundList as $f): ?>
                                <?php
                                    $ts = (int)($f['ts'] ?? 0);
                                    $sumMinor = (int)($f['sum_minor'] ?? 0);
                                    $cmt = trim((string)($f['comment'] ?? ''));
                                    $u = trim((string)($f['user'] ?? ''));
                                    $bid = (int)($f['binding_id'] ?? 0);
                                    $sumVnd = $posterCentsToVnd($sumMinor);
                                    $line = date('d.m.Y', $ts) . ' - ' . date('H:i:s', $ts) . ' - ' . $fmtVnd($sumVnd);
                                    if ($u !== '') $line .= ' - ' . $u;
                                    if ($cmt !== '') $line .= ' - ' . $cmt;
                                ?>
                                <div><button type="button" class="finance-del" data-kind="tips" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button><?= htmlspecialchars($line) ?></div>
                            <?php endforeach; ?>
                        <?php elseif ($tipsDisabled): ?>"""

new_tips_status = """                        <?php if ($transferTipsExists): ?>
                            <table class="table" style="margin-top:5px; font-size:12px;">
                                <thead><tr><th>Дата</th><th>Время</th><th>Сумма совпадает</th><th>Тип</th><th>Комментарий</th><th></th></tr></thead>
                                <tbody>
                            <?php foreach ($transferTipsFoundList as $f): ?>
                                <?php
                                    $ts = (int)($f['ts'] ?? 0);
                                    $sumMinor = (int)($f['sum_minor'] ?? 0);
                                    $cmt = trim((string)($f['comment'] ?? ''));
                                    $u = trim((string)($f['user'] ?? ''));
                                    $bid = (int)($f['binding_id'] ?? 0);
                                    $sumVnd = $posterCentsToVnd($sumMinor);
                                    
                                    $sumMatch = ($sumVnd === (int)($tipsVnd ?? 0));
                                    $sumIcon = $sumMatch ? '<span style="color:#81c784; font-weight:900;">✓</span>' : '<span style="color:#e57373; font-weight:900;">✕</span>';
                                    
                                    $tRaw = (string)($f['type'] ?? '');
                                    $typeText = ($tRaw === '2') ? 'Перевод' : (($tRaw === '0' || strtolower($tRaw) === 'o' || strtolower($tRaw) === 'out') ? 'Расход' : (($tRaw === '1' || strtolower($tRaw) === 'i' || strtolower($tRaw) === 'in') ? 'Приход' : $tRaw));
                                    
                                    $commentText = $u !== '' ? "$cmt ($u)" : $cmt;
                                    $dateStr = date('d.m.Y', $ts);
                                    $timeStr = date('H:i:s', $ts);
                                    $sumText = $sumIcon . ' ' . $fmtVnd($sumVnd);
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($dateStr) ?></td>
                                    <td><?= htmlspecialchars($timeStr) ?></td>
                                    <td><?= $sumText ?></td>
                                    <td><?= htmlspecialchars($typeText) ?></td>
                                    <td><?= htmlspecialchars($commentText) ?></td>
                                    <td style="text-align:right;"><button type="button" class="finance-del btn tiny" style="padding:0 4px;" data-kind="tips" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button></td>
                                </tr>
                            <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php elseif ($tipsDisabled): ?>"""
content = content.replace(old_tips_status, new_tips_status)

# 5. JS Changes
old_js = """    document.querySelectorAll('button.finance-refresh').forEach((btn) => {
        btn.addEventListener('click', () => {
            const form = btn.closest('form.finance-transfer');
            if (!form) return;
            const kind = String(form.getAttribute('data-kind') || '');
            const dateFrom = String(form.getAttribute('data-date-from') || '');
            const dateTo = String(form.getAttribute('data-date-to') || '');
            const accountFrom = Number(form.getAttribute('data-account-from-id') || 0);
            const accountTo = Number(form.getAttribute('data-account-to-id') || 0);
            const statusEl = form.querySelector('.finance-status');
            if (!kind || !dateFrom || !dateTo || !accountFrom || !accountTo || !statusEl) return;
            if (btn.disabled) return;
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '...';
            fetch('?ajax=refresh_finance_transfers', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kind, dateFrom, dateTo, accountFrom, accountTo }),
            })
            .then((r) => r.json())
            .then((j) => {
                if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                const rows = Array.isArray(j.rows) ? j.rows : [];
                if (!rows.length) {
                    statusEl.innerHTML = '<span style=\"color:var(--muted);\">Транзакций не найдено</span>';
                    return;
                }
                const header = '<span style=\"color:#81c784; font-weight:900;\">Найдены транзакции за период:</span>';
                const items = rows.map((x) => {
                    const ts = Number(x.ts || 0);
                    const d = ts ? new Date(ts * 1000) : null;
                    const pad = (n) => String(n).padStart(2, '0');
                    const date = d ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}` : '';
                    const time = d ? `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}` : '';
                    const sum = Number(x.sum || 0);
                    const user = String(x.user || '').trim();
                    const comment = String(x.comment || '').trim();
                    let line = `${date} - ${time} - ${Math.round(sum).toLocaleString('en-US').replace(/,/g, '\\u202F')}`;
                    if (user) line += ` - ${user}`;
                    if (comment) line += ` - ${comment}`;
                    const transferId = Number(x.transfer_id || 0);
                    const txId = Number(x.transaction_id || 0);
                    return `<div><button type=\"button\" class=\"finance-del\" data-kind=\"${escapeHtml(kind)}\" data-transfer-id=\"${transferId}\" data-tx-id=\"${txId}\" data-date-to=\"${escapeHtml(dateTo)}\" title=\"Скрыть транзакцию\">✕</button>${escapeHtml(line)}</div>`;
                }).join('');
                statusEl.innerHTML = header + items;
                bindFinanceDeleteBtns(statusEl);
            })
            .catch((e) => {
                statusEl.textContent = e && e.message ? e.message : 'Ошибка';
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = orig;
            });
        });
    });"""

new_js = """    const refreshAllBtn = document.getElementById('finance-refresh-all');
    if (refreshAllBtn) {
        refreshAllBtn.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            if (btn.disabled) return;
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '...';

            const forms = document.querySelectorAll('form.finance-transfer');
            try {
                for (const form of forms) {
                    const kind = String(form.getAttribute('data-kind') || '');
                    const dateFrom = String(form.getAttribute('data-date-from') || '');
                    const dateTo = String(form.getAttribute('data-date-to') || '');
                    const accountFrom = Number(form.getAttribute('data-account-from-id') || 0);
                    const accountTo = Number(form.getAttribute('data-account-to-id') || 0);
                    const expectedSum = Number(form.getAttribute('data-sum-vnd') || 0);
                    const statusEl = form.querySelector('.finance-status');
                    
                    if (!kind || !dateFrom || !dateTo || !accountFrom || !accountTo || !statusEl) continue;
                    
                    statusEl.innerHTML = '<span style=\"color:var(--muted);\">Обновление...</span>';
                    
                    try {
                        const r = await fetch('?ajax=refresh_finance_transfers', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ kind, dateFrom, dateTo, accountFrom, accountTo }),
                        });
                        const j = await r.json();
                        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка');
                        const rows = Array.isArray(j.rows) ? j.rows : [];
                        if (!rows.length) {
                            statusEl.innerHTML = '<span style=\"color:var(--muted);\">Транзакций не найдено</span>';
                            continue;
                        }
                        
                        let tableHTML = '<table class=\"table\" style=\"margin-top:5px; font-size:12px;\">';
                        tableHTML += '<thead><tr><th>Дата</th><th>Время</th><th>Сумма совпадает</th><th>Тип</th><th>Комментарий</th><th></th></tr></thead><tbody>';
                        
                        rows.forEach((x) => {
                            const ts = Number(x.ts || 0);
                            const d = ts ? new Date(ts * 1000) : null;
                            const pad = (n) => String(n).padStart(2, '0');
                            const dateStr = d ? `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}` : '';
                            const timeStr = d ? `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}` : '';
                            
                            const sum = Number(x.sum || 0);
                            const sumMatch = (sum === expectedSum);
                            const sumIcon = sumMatch ? '<span style=\"color:#81c784; font-weight:900;\">✓</span>' : '<span style=\"color:#e57373; font-weight:900;\">✕</span>';
                            const sumText = `${sumIcon} ${Math.round(sum).toLocaleString('en-US').replace(/,/g, '\\u202F')}`;
                            
                            const typeRaw = String(x.type || '');
                            const typeText = (typeRaw === '2') ? 'Перевод' : ((typeRaw === '0' || typeRaw.toLowerCase() === 'o' || typeRaw.toLowerCase() === 'out') ? 'Расход' : ((typeRaw === '1' || typeRaw.toLowerCase() === 'i' || typeRaw.toLowerCase() === 'in') ? 'Приход' : typeRaw));
                            
                            const comment = String(x.comment || '').trim();
                            const user = String(x.user || '').trim();
                            const commentText = user ? `${comment} (${user})` : comment;
                            
                            const transferId = Number(x.transfer_id || 0);
                            const txId = Number(x.transaction_id || 0);
                            
                            const delBtn = `<button type=\"button\" class=\"finance-del btn tiny\" style=\"padding:0 4px;\" data-kind=\"${escapeHtml(kind)}\" data-transfer-id=\"${transferId}\" data-tx-id=\"${txId}\" data-date-to=\"${escapeHtml(dateTo)}\" title=\"Скрыть транзакцию\">✕</button>`;
                            
                            tableHTML += `<tr><td>${escapeHtml(dateStr)}</td><td>${escapeHtml(timeStr)}</td><td>${sumText}</td><td>${escapeHtml(typeText)}</td><td>${escapeHtml(commentText)}</td><td style=\"text-align:right;\">${delBtn}</td></tr>`;
                        });
                        tableHTML += '</tbody></table>';
                        
                        statusEl.innerHTML = tableHTML;
                        bindFinanceDeleteBtns(statusEl);
                    } catch (e) {
                        statusEl.textContent = e && e.message ? e.message : 'Ошибка';
                    }
                }
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    }"""
content = content.replace(old_js, new_js)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("HTML/JS Update done")
