<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$loadCall = <<<HTML
    document.querySelectorAll('form.finance-transfer').forEach((form) => {
        if (window.refreshFinanceForm) window.refreshFinanceForm(form, { showLoading: false });
    });
HTML;

$js = str_replace("updateLinkButtonState();", "updateLinkButtonState();\n" . $loadCall, $js);
file_put_contents('payday2/assets/js/payday2.js', $js);
