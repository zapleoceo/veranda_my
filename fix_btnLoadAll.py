with open('/workspace/payday/index.php', 'r') as f:
    content = f.read()

load_all_code = """
    const btnLoadAll = document.getElementById('btnLoadAll');
    if (btnLoadAll) {
        btnLoadAll.addEventListener('click', async () => {
            const restore = setBtnBusy(btnLoadAll, { title: 'Загрузить всё', pct: 0 });
            try {
                const fdPoster = new URLSearchParams();
                fdPoster.append('action', 'load_poster_checks');
                fdPoster.append('ajax', '1');
                fdPoster.append('dateFrom', dateFrom.value);
                fdPoster.append('dateTo', dateTo.value);

                const fdSepay = new URLSearchParams();
                fdSepay.append('action', 'reload_sepay_api');
                fdSepay.append('ajax', '1');
                fdSepay.append('dateFrom', dateFrom.value);
                fdSepay.append('dateTo', dateTo.value);

                const p1 = fetch(location.pathname, { method: 'POST', body: fdPoster }).then(r => r.text());
                const p2 = fetch(location.pathname, { method: 'POST', body: fdSepay }).then(r => r.text());

                const p3 = loadOutMail((pct, step) => updateBtnBusy(btnLoadAll, { pct, title: 'Загрузка...' }));
                const p4 = loadOutFinance((pct, step) => updateBtnBusy(btnLoadAll, { pct, title: 'Загрузка...' }));

                await Promise.all([p1, p2, p3, p4]);

                const html = await fetch(location.href).then(r => r.text());
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                const newSepayTbody = doc.querySelector('#sepayTable tbody');
                const newPosterTbody = doc.querySelector('#posterTable tbody');

                if (newSepayTbody) {
                    const oldTbody = document.querySelector('#sepayTable tbody');
                    if (oldTbody) oldTbody.innerHTML = newSepayTbody.innerHTML;
                }
                if (newPosterTbody) {
                    const oldTbody = document.querySelector('#posterTable tbody');
                    if (oldTbody) {
                        const injectedRows = Array.from(oldTbody.querySelectorAll('tr.row-out-positive'));
                        oldTbody.innerHTML = newPosterTbody.innerHTML;
                        injectedRows.forEach(tr => oldTbody.appendChild(tr));
                    }
                }

                if (typeof bindInTableEvents === 'function') bindInTableEvents();
                updateStats();
                applyHideLinked();
                drawLines();
                try { scheduleRelayoutBurst(); } catch (e) {}

            } catch (e) {
                alert(e && e.message ? e.message : 'Ошибка');
            } finally {
                restore();
            }
        });
    }
"""

content = content.replace("    if (btnSupplies && suppliesModal) {", load_all_code + "\n    if (btnSupplies && suppliesModal) {")

with open('/workspace/payday/index.php', 'w') as f:
    f.write(content)
