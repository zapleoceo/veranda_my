window.initPaydayTelegramScreenshot = function() {
    const btn = document.getElementById('posterBalancesTelegramBtn');
    if (!btn || btn.dataset.tgInit) return;
    btn.dataset.tgInit = '1';

    function loadHtml2Canvas() {
        return new Promise((resolve, reject) => {
            if (window.html2canvas) return resolve(window.html2canvas);
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
            script.onload = () => resolve(window.html2canvas);
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    btn.addEventListener('click', async () => {
        const container = document.querySelector('.card.card-balances');
        if (!container) return alert('Контейнер таблицы Итоговый баланс не найден');

        let restoreFn = null;
        if (typeof setBtnBusy === 'function') {
            restoreFn = setBtnBusy(btn, { title: 'Telegram', pct: 0 });
        } else {
            const origHtml = btn.innerHTML;
            btn.innerHTML = 'Загрузка...';
            btn.disabled = true;
            restoreFn = () => { btn.innerHTML = origHtml; btn.disabled = false; };
        }

        try {
            if (typeof updateBtnBusy === 'function') updateBtnBusy(btn, { pct: 20, title: 'Рендер...' });
            
            const html2canvas = await loadHtml2Canvas();

            const origWidth = container.style.width;
            const origMaxWidth = container.style.maxWidth;
            const origOverflow = container.style.overflowX;
            const origMargin = container.style.margin;

            // Force width to 400px for screenshot
            container.classList.add('telegram-capture');
            container.style.width = '400px';
            container.style.maxWidth = '400px';
            container.style.overflowX = 'hidden';
            container.style.margin = '0';

            if (typeof updateBtnBusy === 'function') updateBtnBusy(btn, { pct: 40, title: 'Скриншот...' });

            // Replace inputs with visually identical divs to avoid html2canvas input text shifting bugs
            const inputs = Array.from(container.querySelectorAll('input'));
            const replacements = inputs.map(inp => {
                const fake = document.createElement('div');
                fake.className = inp.className + ' fake-input';
                fake.textContent = inp.value;
                inp.parentNode.insertBefore(fake, inp);
                inp.style.display = 'none';
                return { inp, fake };
            });

            // Wait a tick for layout recalculation
            await new Promise(r => setTimeout(r, 100));

            const canvas = await html2canvas(container, {
                scale: 2,
                useCORS: true,
                backgroundColor: getComputedStyle(document.body).backgroundColor || '#1f2937'
            });

            // Revert fake inputs back to real inputs
            replacements.forEach(r => {
                r.fake.remove();
                r.inp.style.display = '';
            });

            // Restore original styles immediately after snapshot
            container.classList.remove('telegram-capture');
            container.style.width = origWidth;
            container.style.maxWidth = origMaxWidth;
            container.style.overflowX = origOverflow;
            container.style.margin = origMargin;

            if (typeof updateBtnBusy === 'function') updateBtnBusy(btn, { pct: 60, title: 'Конвертация...' });

            const dataUrl = canvas.toDataURL('image/png');

            if (typeof updateBtnBusy === 'function') updateBtnBusy(btn, { pct: 80, title: 'Отправка...' });

            const res = await fetch('?ajax=poster_balances_telegram_screenshot', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ image: dataUrl })
            });

            const j = await res.json();
            if (!res.ok || !j || !j.ok) throw new Error((j && j.error) ? j.error : 'Ошибка отправки в Telegram');

            if (typeof updateBtnBusy === 'function') updateBtnBusy(btn, { pct: 100, title: 'Отправлено' });
            setTimeout(() => alert('Скриншот итогового баланса успешно отправлен в Telegram!'), 100);
        } catch (e) {
            alert(e && e.message ? e.message : 'Ошибка отправки в Telegram');
        } finally {
            setTimeout(() => { if (restoreFn) restoreFn(); }, 2000);
        }
    });
};
