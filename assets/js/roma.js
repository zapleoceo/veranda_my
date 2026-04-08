const elFrom = document.getElementById('dateFrom');
    const elTo = document.getElementById('dateTo');
    const btn = document.getElementById('loadBtn');
    const loader = document.getElementById('loader');
    const err = document.getElementById('err');
    const tbody = document.getElementById('tbody');
    const tfoot = document.getElementById('tfoot');
    const romaSum = document.getElementById('romaSum');

    const setLoading = (on) => {
        btn.disabled = on;
        loader.style.display = on ? 'inline-flex' : 'none';
    };

    const setError = (msg) => {
        if (!msg) { err.style.display = 'none'; err.textContent = ''; return; }
        err.style.display = 'block';
        err.textContent = msg;
    };

    const load = async () => {
        setError('');
        setLoading(true);
        tbody.innerHTML = '';
        tfoot.innerHTML = '';
        romaSum.textContent = '0';
        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'load');
            url.searchParams.set('date_from', elFrom.value);
            url.searchParams.set('date_to', elTo.value);
            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
            const txt = await res.text();
            let j = null;
            try { j = JSON.parse(txt); } catch (_) {}
            if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка загрузки');

            (j.items || []).forEach((it) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${String(it.product_name || '')}</td>
                    <td class="num">${String(it.count || '0')}</td>
                    <td class="num">${String(it.sum || '0')}</td>
                `;
                tbody.appendChild(tr);
            });

            const trTot = document.createElement('tr');
            trTot.className = 'total';
            trTot.innerHTML = `
                <td>Итого</td>
                <td class="num">${String(j.totals?.count || '0')}</td>
                <td class="num">${String(j.totals?.sum || '0')}</td>
            `;
            tfoot.appendChild(trTot);
            romaSum.textContent = String(j.roma?.sum || '0');
        } catch (e) {
            setError(e && e.message ? e.message : 'Ошибка');
        } finally {
            setLoading(false);
        }
    };

    btn.addEventListener('click', () => load());
    load();
