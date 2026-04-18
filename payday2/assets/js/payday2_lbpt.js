window.initPaydayLbpt = function() {
    const btn = document.getElementById('lbptBtn');
    if (!btn || btn.dataset.lbptInit) return;
    btn.dataset.lbptInit = '1';

    btn.addEventListener('click', async () => {
        const dateTo = window.PAYDAY_CONFIG?.dateTo || document.getElementById('dateTo')?.value;
        if (!dateTo) {
            alert('Выберите дату (dateTo)');
            return;
        }

        const origHtml = btn.innerHTML;
        btn.innerHTML = 'Поиск...';
        btn.disabled = true;

        try {
            const url = new URL(location.href);
            url.searchParams.set('ajax', 'telegram_search_fact');
            
            const res = await fetch(url.toString(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ dateTo: dateTo })
            });

            const j = await res.json().catch(() => null);
            if (!res.ok || !j || !j.ok) {
                throw new Error((j && j.error) ? j.error : 'Ошибка поиска');
            }

            showLbptModal(j.message_text || 'Сообщение найдено, но текст пуст');

        } catch (err) {
            alert(err.message || 'Ошибка');
        } finally {
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }
    });

    function showLbptModal(text) {
        let modal = document.getElementById('lbptModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'lbptModal';
            modal.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; display:flex; align-items:center; justify-content:center;';
            
            const content = document.createElement('div');
            content.style.cssText = 'background:var(--card, #fff); padding:20px; border-radius:8px; max-width:500px; width:90%; box-shadow:0 4px 6px rgba(0,0,0,0.1);';
            
            const title = document.createElement('h3');
            title.textContent = 'Результат поиска';
            title.style.marginTop = '0';
            
            const msgText = document.createElement('pre');
            msgText.id = 'lbptModalText';
            msgText.style.cssText = 'white-space:pre-wrap; word-wrap:break-word; background:var(--bg, #f5f5f5); padding:10px; border-radius:4px; max-height:300px; overflow-y:auto;';
            
            const btnWrap = document.createElement('div');
            btnWrap.style.cssText = 'text-align:right; margin-top:15px;';
            
            const okBtn = document.createElement('button');
            okBtn.className = 'btn';
            okBtn.textContent = 'OK';
            okBtn.onclick = () => { modal.style.display = 'none'; };
            
            btnWrap.appendChild(okBtn);
            content.appendChild(title);
            content.appendChild(msgText);
            content.appendChild(btnWrap);
            modal.appendChild(content);
            document.body.appendChild(modal);
        }
        
        document.getElementById('lbptModalText').textContent = text;
        modal.style.display = 'flex';
    }
};

document.addEventListener('DOMContentLoaded', () => {
    if (window.initPaydayLbpt) window.initPaydayLbpt();
});
window.initPaydayLbpt();
