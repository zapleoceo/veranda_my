const elFrom = document.getElementById('dateFrom');
const elTo = document.getElementById('dateTo');
const btn = document.getElementById('loadBtn');
const loader = document.getElementById('loader');
const err = document.getElementById('err');
const tbody = document.getElementById('tbody');
const tfoot = document.getElementById('tfoot');
const romaBase = document.getElementById('romaBase');
const romaSum = document.getElementById('romaSum');
const romaPct = document.getElementById('romaPct');
const romaPctLabel = document.getElementById('romaPctLabel');

const PCT_KEY = 'roma_pct';

// База (payed_sum) в минорных единицах — храним, чтобы пересчитывать процент
// на лету без повторного запроса в Poster.
let basePayedMinor = 0;

const fmtVnd = (minor) => {
    const vnd = Math.round((Number(minor) || 0) / 100);
    const sign = vnd < 0 ? '-' : '';
    return sign + String(Math.abs(vnd)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
};

const currentPct = () => {
    const p = parseFloat(String(romaPct.value || '').replace(',', '.'));
    return (isNaN(p) || p < 0) ? 0 : p;
};

// Пересчёт «база * процент = сумма» целиком на клиенте — мгновенно.
const recalc = () => {
    const pct = currentPct();
    romaPctLabel.textContent = String(pct);
    romaSum.textContent = fmtVnd(basePayedMinor * pct / 100);
};

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
    basePayedMinor = 0;
    romaBase.textContent = '0';
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
                <td class="num">${String(it.price || '0')}</td>
                <td class="num">${String(it.discount || '0')}</td>
                <td class="num">${String(it.payed_sum || '0')}</td>
                <td class="num">${String(it.sum || '0')}</td>
            `;
            tbody.appendChild(tr);
        });

        const trTot = document.createElement('tr');
        trTot.className = 'total';
        trTot.innerHTML = `
            <td>Итого</td>
            <td class="num">${String(j.totals?.count || '0')}</td>
            <td class="num">${String(j.totals?.price || '0')}</td>
            <td class="num">${String(j.totals?.discount || '0')}</td>
            <td class="num">${String(j.totals?.payed_sum || '0')}</td>
            <td class="num">${String(j.totals?.sum || '0')}</td>
        `;
        tfoot.appendChild(trTot);
        romaBase.textContent = String(j.totals?.payed_sum || '0');
        basePayedMinor = Number(j.totals?.payed_minor || 0) || 0;
        recalc();
    } catch (e) {
        setError(e && e.message ? e.message : 'Ошибка');
    } finally {
        setLoading(false);
    }
};

// Восстановить сохранённый процент до первой загрузки.
try {
    const saved = localStorage.getItem(PCT_KEY);
    if (saved !== null && saved !== '') romaPct.value = saved;
} catch (_) {}
recalc();

romaPct.addEventListener('input', () => {
    try { localStorage.setItem(PCT_KEY, romaPct.value); } catch (_) {}
    recalc();
});

btn.addEventListener('click', () => load());
load();
