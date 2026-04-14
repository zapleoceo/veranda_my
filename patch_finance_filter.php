<?php
$js = file_get_contents('payday2/assets/js/payday2.js');
$old = <<<HTML
        console.log('renderFinanceTable rows:', rows, 'expectedSum:', expectedSum);
        // Compare with a tiny tolerance to account for floating point issues, or just convert to Int
        const match = rows.filter((x) => {
            const rowSum = Math.abs(Number(x.sum || 0));
            const expSum = Math.abs(Number(expectedSum));
            return Math.abs(rowSum - expSum) < 1;
        });
        if (!match.length) {
            statusEl.innerHTML = '<span style="color:var(--muted);">Транзакция не найдена</span>';
            return;
        }
HTML;
$new = <<<HTML
        console.log('renderFinanceTable rows:', rows, 'expectedSum:', expectedSum);
        const match = rows;
        if (!match.length) {
            statusEl.innerHTML = '<span style="color:var(--muted);">Транзакция не найдена</span>';
            return;
        }
HTML;
$js = str_replace($old, $new, $js);

// Also we need to make sure the form button is hidden when the table is shown!
// Wait, the table is rendered INSIDE `statusEl`. The button is OUTSIDE.
// We can find the button and hide it.
$btnHide = <<<HTML
        const btnSubmit = form.querySelector('button[type="submit"]');
        if (match.length > 0) {
            if (btnSubmit) btnSubmit.style.display = 'none';
        } else {
            if (btnSubmit) btnSubmit.style.display = '';
        }
HTML;
$js = str_replace("if (!match.length) {", $btnHide . "\n        if (!match.length) {", $js);

file_put_contents('payday2/assets/js/payday2.js', $js);
