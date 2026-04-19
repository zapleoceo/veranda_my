window.initPaydayCreateTx = function() {
    const modal = document.getElementById('createTxModal');
    const closeBtn = document.getElementById('createTxClose');
    const form = document.getElementById('createTxForm');
    
    if (!modal || !closeBtn || !form) return;

    const dateInput = document.getElementById('createTxDate');
    const timeInput = document.getElementById('createTxTime');
    const typeSelect = document.getElementById('createTxType');
    const accFromWrap = document.getElementById('createTxAccountFromWrap');
    const accFromSelect = document.getElementById('createTxAccountFrom');
    const accToWrap = document.getElementById('createTxAccountToWrap');
    const accToSelect = document.getElementById('createTxAccountTo');
    const amountInput = document.getElementById('createTxAmount');
    const categorySelect = document.getElementById('createTxCategory');
    const commentInput = document.getElementById('createTxComment');
    const errorDiv = document.getElementById('createTxError');
    const submitBtn = document.getElementById('createTxSubmitBtn');

    const successModal = document.getElementById('createTxSuccessModal');
    const successCloseBtn = document.getElementById('createTxSuccessClose');
    const successDetails = document.getElementById('createTxSuccessDetails');

    let accountsLoaded = false;
    let categoriesLoaded = false;

    const escapeHtml = (str) => {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const showError = (msg) => {
        if (!msg) {
            errorDiv.textContent = '';
            errorDiv.classList.add('pd2-d-none');
        } else {
            errorDiv.textContent = msg;
            errorDiv.classList.remove('pd2-d-none');
        }
    };

    const loadOptions = async () => {
        try {
            if (!accountsLoaded) {
                const r = await fetch('?ajax=finance_accounts');
                const j = await r.json();
                if (!j || !j.ok) throw new Error(j?.error || 'Ошибка загрузки счетов');
                
                accFromSelect.innerHTML = '<option value="">Выберите счет...</option>';
                accToSelect.innerHTML = '<option value="">Выберите счет...</option>';
                
                for (const [id, name] of Object.entries(j.accounts || {})) {
                    accFromSelect.insertAdjacentHTML('beforeend', `<option value="${id}">${name}</option>`);
                    accToSelect.insertAdjacentHTML('beforeend', `<option value="${id}">${name}</option>`);
                }
                accountsLoaded = true;
            }

            if (!categoriesLoaded) {
                const r = await fetch('?ajax=finance_categories');
                const j = await r.json();
                if (!j || !j.ok) throw new Error(j?.error || 'Ошибка загрузки категорий');
                
                categorySelect.innerHTML = '<option value="">Без категории</option>';
                for (const [id, name] of Object.entries(j.categories || {})) {
                    categorySelect.insertAdjacentHTML('beforeend', `<option value="${id}">${name}</option>`);
                }
                categoriesLoaded = true;
            }
        } catch (e) {
            showError(e.message);
        }
    };

    const openModal = async (amount, dateStr, timeStr) => {
        showError('');
        
        if (dateStr && dateStr.includes('/')) {
            const parts = dateStr.split('/');
            if (parts.length === 3) {
                dateInput.value = `${parts[2]}-${parts[1]}-${parts[0]}`;
            }
        } else {
            const now = new Date();
            dateInput.value = now.toISOString().split('T')[0];
        }

        if (timeStr) {
            timeInput.value = timeStr.slice(0, 5);
        } else {
            const now = new Date();
            timeInput.value = now.toTimeString().slice(0, 5);
        }

        typeSelect.value = '2'; // Расход
        typeSelect.dispatchEvent(new Event('change'));

        amountInput.value = Math.round(Number(amount || 0));
        
        const userEmail = (window.PAYDAY_CONFIG && window.PAYDAY_CONFIG.userEmail) ? window.PAYDAY_CONFIG.userEmail : 'User';
        commentInput.value = `Created by ${userEmail}`;

        modal.style.display = 'flex';
        
        submitBtn.disabled = true;
        await loadOptions();
        submitBtn.disabled = false;
    };

    const closeModal = () => {
        modal.style.display = 'none';
        form.reset();
        showError('');
    };

    const openSuccessModal = (detailsHtml) => {
        if (!successModal || !successDetails) return;
        successDetails.innerHTML = detailsHtml;
        successModal.style.display = 'flex';
    };

    const closeSuccessModal = () => {
        if (successModal) successModal.style.display = 'none';
        // Click the refresh button of out table instead of reloading the whole page via form submit
        const outMailBtn = document.getElementById('outMailBtn');
        if (outMailBtn) {
            outMailBtn.click();
        }
    };

    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    if (successCloseBtn) successCloseBtn.addEventListener('click', closeSuccessModal);
    if (successModal) {
        successModal.addEventListener('click', (e) => {
            if (e.target === successModal) closeSuccessModal();
        });
    }

    typeSelect.addEventListener('change', () => {
        const t = typeSelect.value;
        if (t === '1') { // Приход
            accFromWrap.classList.add('pd2-d-none');
            accToWrap.classList.remove('pd2-d-none');
        } else if (t === '2') { // Расход
            accFromWrap.classList.remove('pd2-d-none');
            accToWrap.classList.add('pd2-d-none');
        } else if (t === '3') { // Перевод
            accFromWrap.classList.remove('pd2-d-none');
            accToWrap.classList.remove('pd2-d-none');
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        showError('');

        const t = typeSelect.value;
        const date = dateInput.value;
        const time = timeInput.value;
        const amount = Number(amountInput.value);
        const accFrom = Number(accFromSelect.value);
        const accTo = Number(accToSelect.value);
        const categoryId = Number(categorySelect.value);
        const comment = commentInput.value.trim();

        if (!date || !time) return showError('Укажите дату и время');
        if (amount <= 0) return showError('Сумма должна быть больше 0');

        if (t === '1' && !accTo) return showError('Укажите счет пополнения');
        if (t === '2' && !accFrom) return showError('Укажите счет списания');
        if (t === '3') {
            if (!accFrom || !accTo) return showError('Укажите оба счета');
            if (accFrom === accTo) return showError('Счета должны быть разными');
        }

        const payload = {
            type: Number(t),
            amount: amount,
            date: `${date} ${time}:00`,
            comment: comment,
            category_id: categoryId,
            account_from: accFrom,
            account_to: accTo
        };

        submitBtn.disabled = true;
        const oldHtml = submitBtn.innerHTML;
        submitBtn.innerHTML = 'Создание...';

        try {
            const r = await fetch('?ajax=create_poster_transaction', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-PAYDAY2-CSRF': window.PAYDAY_CONFIG?.csrfToken || ''
                },
                body: JSON.stringify(payload)
            });
            const j = await r.json();
            
            if (!j || !j.ok) throw new Error(j?.error || 'Ошибка при создании транзакции');
            
            closeModal();
            
            let details = '';
            const tText = typeSelect.options[typeSelect.selectedIndex].text;
            details += `<div><strong>Тип:</strong> ${escapeHtml(tText)}</div>`;
            details += `<div><strong>Сумма:</strong> ${Math.round(amount).toLocaleString('en-US').replace(/,/g, '\u202F')} VND</div>`;
            if (t === '1') details += `<div><strong>На счет:</strong> ${escapeHtml(accToSelect.options[accToSelect.selectedIndex].text)}</div>`;
            if (t === '2') details += `<div><strong>Со счета:</strong> ${escapeHtml(accFromSelect.options[accFromSelect.selectedIndex].text)}</div>`;
            if (t === '3') {
                details += `<div><strong>Со счета:</strong> ${escapeHtml(accFromSelect.options[accFromSelect.selectedIndex].text)}</div>`;
                details += `<div><strong>На счет:</strong> ${escapeHtml(accToSelect.options[accToSelect.selectedIndex].text)}</div>`;
            }
            if (categoryId) details += `<div><strong>Категория:</strong> ${escapeHtml(categorySelect.options[categorySelect.selectedIndex].text)}</div>`;
            
            openSuccessModal(details);
            
        } catch (err) {
            showError(err.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = oldHtml;
        }
    });

    // Global click listener to open modal
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.out-create-poster-tx-btn');
        if (btn) {
            const amount = btn.getAttribute('data-amount');
            const date = btn.getAttribute('data-date');
            const time = btn.getAttribute('data-time');
            openModal(amount, date, time);
        }
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', window.initPaydayCreateTx);
} else {
    window.initPaydayCreateTx();
}