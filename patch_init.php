<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$loadCall = <<<HTML
    document.querySelectorAll('form.finance-transfer').forEach((form) => {
        window.refreshFinanceForm(form, { showLoading: false });
    });
HTML;

$js = str_replace("window.refreshFinanceForm = (form, options = {}) => {", $loadCall . "\n\n    window.refreshFinanceForm = (form, options = {}) => {", $js);
file_put_contents('payday2/assets/js/payday2.js', $js);
