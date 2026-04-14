import re

with open('payday/index.php', 'r', encoding='utf-8') as f:
    content = f.read()

# Replace the thead for Vietnam & Tips
content = re.sub(
    r'<thead><tr><th>Дата</th><th>Сумма</th><th>Комментарий</th></tr></thead>',
    r'<thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Комментарий</th><th style="padding:2px 0px; width:1%;"></th></tr></thead>',
    content
)

# Remove min-width: 420px;
content = content.replace(
    '<table class="table" style="margin-top:5px; font-size:12px; width:100%; min-width: 420px;">',
    '<table class="table" style="margin-top:5px; font-size:12px; width:100%;">'
)

# Replace the td structure in PHP loops
old_td_php = r"""                                            <td>
                                                <div><\?= htmlspecialchars\(\$dateStr\) \?></div>
                                                <div class="muted"><\?= htmlspecialchars\(\$timeStr\) \?></div>
                                            </td>
                                            <td style="white-space:nowrap;"><\?= \$fmtVnd\(\$sumSignedVnd\) \?></td>
                                            <td>
                                                <div style="display:flex; justify-content:space-between; align-items:center; gap:5px;">
                                                    <span style="line-height:1.2;"><\?= htmlspecialchars\(\$commentText\) \?></span>
                                                    <button type="button" class="finance-del btn tiny" style="padding:0 4px; flex:0 0 auto;" data-kind="(.*?)" data-transfer-id="<\?= \(int\)\(\$f\['transfer_id'\] \?\? 0\) \?>" data-tx-id="<\?= \(int\)\(\$f\['transaction_id'\] \?\? 0\) \?>" data-date-to="<\?= htmlspecialchars\(\$dateTo\) \?>" title="Скрыть транзакцию">✕</button>
                                                </div>
                                            </td>"""

new_td_php = r"""                                            <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                            <td style="padding:2px 4px; white-space:nowrap;"><?= $fmtVnd($sumSignedVnd) ?></td>
                                            <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($commentText) ?></td>
                                            <td style="padding:2px 0px; text-align:right; width:1%;"><button type="button" class="finance-del btn tiny" style="padding:0 2px;" data-kind="\1" data-transfer-id="<?= (int)($f['transfer_id'] ?? 0) ?>" data-tx-id="<?= (int)($f['transaction_id'] ?? 0) ?>" data-date-to="<?= htmlspecialchars($dateTo) ?>" title="Скрыть транзакцию">✕</button></td>"""

content = re.sub(old_td_php, new_td_php, content, flags=re.DOTALL)

# Replace the td structure in JS loop
old_td_js = r"""            html \+= `<tr>
                <td><div>\$\{escapeHtml\(dateStr\)\}</div><div class="muted">\$\{escapeHtml\(timeStr\)\}</div></td>
                <td style="white-space:nowrap;">\$\{Math\.round\(sumSigned\)\.toLocaleString\('en-US'\)\.replace\(/,/g, '\\u202F'\)\}</td>
                <td>
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:5px;">
                        <span style="line-height:1.2;">\$\{escapeHtml\(commentText\)\}</span>
                        \$\{delBtn\}
                    </div>
                </td>
            </tr>`;"""

new_td_js = r"""            html += `<tr>
                <td style="padding:2px 4px; white-space:nowrap;">${escapeHtml(dateStr)}<br><span class="muted">${escapeHtml(timeStr)}</span></td>
                <td style="padding:2px 4px; white-space:nowrap;">${Math.round(sumSigned).toLocaleString('en-US').replace(/,/g, '\\u202F')}</td>
                <td style="padding:2px 4px; line-height:1.2;">${escapeHtml(commentText)}</td>
                <td style="padding:2px 0px; text-align:right; width:1%;">${delBtn.replace('padding:0 4px; flex:0 0 auto;', 'padding:0 2px;')}</td>
            </tr>`;"""

content = re.sub(old_td_js, new_td_js, content, flags=re.DOTALL)

with open('payday/index.php', 'w', encoding='utf-8') as f:
    f.write(content)

print("Done table html update 3")
