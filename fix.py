with open('/workspace/payday/index.php', 'r') as f:
    content = f.read()

bad_block = """                if (rawAmount > 0) {
                    const inPosterTbody = document.querySelector('#posterTable tbody');
                    if (inPosterTbody) {
                        const trIn = document.createElement('tr');
                        trIn.setAttribute('data-poster-id', String(row.transaction_id || 0));
                        trIn.setAttribute('data-total', String(amountInt));
                        const dtIn = formatOutDT('', row.date);
                        trIn.innerHTML = `
                            <td class="nowrap col-poster-dot"><span class="anchor" id="poster-${Number(row.transaction_id || 0)}"></span></td>
                            <td class="nowrap col-poster-num">${Number(row.transaction_id || 0)}</td>
                            <td class="nowrap col-poster-time">${escapeHtml(dtIn.time)}</td>
                            <td class="sum col-poster-card">${fmtVnd0(amountInt)}</td>
                            <td class="sum col-poster-tips">0 ₫</td>
                            <td class="sum col-poster-total">${fmtVnd0(amountInt)}</td>
                            <td class="col-poster-method">${escapeHtml(catName)}</td>
                            <td class="col-poster-waiter">${escapeHtml(userName)}</td>
                            <td class="nowrap col-poster-table">${escapeHtml(row.comment || '—')}</td>
                            <td class="col-poster-cb"><input type="checkbox" class="poster-cb" data-id="${Number(row.transaction_id || 0)}"></td>
                        `;
                        inPosterTbody.appendChild(trIn);
                        const newCb = trIn.querySelector('input.poster-cb');
                        if (newCb) {
                            newCb.addEventListener('change', () => {
                                const id = Number(newCb.getAttribute('data-id') || 0);
                                if (!id) return;
                                if (newCb.checked) selectedPoster.add(id);
                                else selectedPoster.delete(id);
                                updateLinkButtonState();
                            });
                        }
                    }
                }"""

content = content.replace(bad_block, "")

with open('/workspace/payday/index.php', 'w') as f:
    f.write(content)
