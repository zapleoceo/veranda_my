with open('/workspace/payday/index.php', 'r') as f:
    content = f.read()

dup = """    const setupSort = (table) => {
        const state = { key: null, dir: 'asc' };
        const ths = Array.from(table.querySelectorAll('th.sortable[data-sort-key]'));
        ths.forEach((th) => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', () => {
                const key = (th.getAttribute('data-sort-key') || '').trim();
                if (!key) return;
                state.dir = (state.key === key && state.dir === 'asc') ? 'desc' : 'asc';
                state.key = key;

                const tbody = table.tBodies && table.tBodies[0] ? table.tBodies[0] : null;
                if (!tbody) return;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.sort((a, b) => {
                    const av = (a.dataset && a.dataset[key]) ? a.dataset[key] : '';
                    const bv = (b.dataset && b.dataset[key]) ? b.dataset[key] : '';
                    const na = Number(av);
                    const nb = Number(bv);
                    let cmp = 0;
                    if (av !== '' && bv !== '' && !Number.isNaN(na) && !Number.isNaN(nb)) {
                        cmp = na - nb;
                    } else {
                        cmp = String(av).localeCompare(String(bv), 'ru', { numeric: true, sensitivity: 'base' });
                    }
                    return state.dir === 'asc' ? cmp : -cmp;
                });
                rows.forEach((r) => tbody.appendChild(r));
                positionLines();
                positionWidgets();
            });
        });
    };

    setupSort(sepayTable);
    setupSort(posterTable);

"""

content = content.replace(dup, "", 1)

with open('/workspace/payday/index.php', 'w') as f:
    f.write(content)
