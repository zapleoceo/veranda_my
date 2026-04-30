(function () {
    if (window.payday2SaveActualBalances) return;

    const parseCents = (id) => {
        const el = document.getElementById(id);
        if (!el) return null;
        const v = String(el.value || '').trim();
        if (v === '') return null;
        const digits = v.replace(/[^\d-]/g, '');
        const num = parseInt(digits, 10);
        return Number.isFinite(num) ? num * 100 : null;
    };

    window.payday2SaveActualBalances = async function () {
        try {
            const payload = {
                target_date: window.PAYDAY_CONFIG ? window.PAYDAY_CONFIG.dateFrom : new Date().toISOString().split('T')[0],
                bal_andrey: parseCents('balAndreyActual'),
                bal_vietnam: parseCents('balVietnamActual'),
                bal_cash: parseCents('balCashActual'),
                bal_total: parseCents('balTotalActual')
            };
            await fetch('?ajax=save_actual_balances', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            return true;
        } catch (_) {
            return false;
        }
    };
})();

