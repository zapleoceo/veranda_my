import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update Vietnam Table
old_vietnam = """                            <table class="table" style="margin-top:5px; font-size:12px;">
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
                            </table>"""

new_vietnam = """                            <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                <thead>
                                    <tr>
                                        <th style="padding:2px 4px;">Дата/Время</th>
                                        <th style="padding:2px 4px;">Сумма</th>
                                        <th style="padding:2px 4px;">Тип</th>
                                        <th style="padding:2px 4px;">Комментарий</th>
                                        <th style="padding:2px 0px; width:1%;"></th>
                                    </tr>
                                </thead>
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
                                    <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                    <td style="padding:2px 4px; white-space:nowrap;"><?= $sumText ?></td>
                                    <td style="padding:2px 4px;"><?= htmlspecialchars($typeText) ?></td>
                                    <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($commentText) ?></td>
                                    <td style="padding:2px 0px; text-align:right; width:1%;"><button type="button" class="finance-del btn tiny" style="padding:0 2px;" data-kind="vietnam" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button></td>
                                </tr>
                            <?php endforeach; ?>
                                </tbody>
                            </table>"""

content = content.replace(old_vietnam, new_vietnam)


# 2. Update Tips Table
old_tips = """                            <table class="table" style="margin-top:5px; font-size:12px;">
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
                            </table>"""

new_tips = """                            <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                <thead>
                                    <tr>
                                        <th style="padding:2px 4px;">Дата/Время</th>
                                        <th style="padding:2px 4px;">Сумма</th>
                                        <th style="padding:2px 4px;">Тип</th>
                                        <th style="padding:2px 4px;">Комментарий</th>
                                        <th style="padding:2px 0px; width:1%;"></th>
                                    </tr>
                                </thead>
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
                                    <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                    <td style="padding:2px 4px; white-space:nowrap;"><?= $sumText ?></td>
                                    <td style="padding:2px 4px;"><?= htmlspecialchars($typeText) ?></td>
                                    <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($commentText) ?></td>
                                    <td style="padding:2px 0px; text-align:right; width:1%;"><button type="button" class="finance-del btn tiny" style="padding:0 2px;" data-kind="tips" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button></td>
                                </tr>
                            <?php endforeach; ?>
                                </tbody>
                            </table>"""

content = content.replace(old_tips, new_tips)

# 3. Update JS Table Renderer
old_js_table = """                        let tableHTML = '<table class=\"table\" style=\"margin-top:5px; font-size:12px;\">';
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
                        tableHTML += '</tbody></table>';"""

new_js_table = """                        let tableHTML = '<table class=\"table\" style=\"margin-top:5px; font-size:12px; width:100%;\">';
                        tableHTML += '<thead><tr><th style=\"padding:2px 4px;\">Дата/Время</th><th style=\"padding:2px 4px;\">Сумма</th><th style=\"padding:2px 4px;\">Тип</th><th style=\"padding:2px 4px;\">Комментарий</th><th style=\"padding:2px 0px; width:1%;\"></th></tr></thead><tbody>';
                        
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
                            
                            const delBtn = `<button type=\"button\" class=\"finance-del btn tiny\" style=\"padding:0 2px;\" data-kind=\"${escapeHtml(kind)}\" data-transfer-id=\"${transferId}\" data-tx-id=\"${txId}\" data-date-to=\"${escapeHtml(dateTo)}\" title=\"Скрыть транзакцию\">✕</button>`;
                            
                            tableHTML += `<tr>
                                <td style=\"padding:2px 4px; white-space:nowrap;\">${escapeHtml(dateStr)}<br><span class=\"muted\">${escapeHtml(timeStr)}</span></td>
                                <td style=\"padding:2px 4px; white-space:nowrap;\">${sumText}</td>
                                <td style=\"padding:2px 4px;\">${escapeHtml(typeText)}</td>
                                <td style=\"padding:2px 4px; line-height:1.2;\">${escapeHtml(commentText)}</td>
                                <td style=\"padding:2px 0px; text-align:right; width:1%;\">${delBtn}</td>
                            </tr>`;
                        });
                        tableHTML += '</tbody></table>';"""

content = content.replace(old_js_table, new_js_table)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Done table html update")
