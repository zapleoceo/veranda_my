<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$badBlock = <<<HTML
updateLinkButtonState();
    document.querySelectorAll('form.finance-transfer').forEach((form) => {
        if (window.refreshFinanceForm) window.refreshFinanceForm(form, { showLoading: false });
    });
HTML;

$js = str_replace($badBlock, "updateLinkButtonState();", $js);

// Put it before the end of initPayday2
$js = str_replace("};\nwindow.initPayday2();", "    document.querySelectorAll('form.finance-transfer').forEach((form) => {\n        if (window.refreshFinanceForm) window.refreshFinanceForm(form, { showLoading: false });\n    });\n};\nwindow.initPayday2();", $js);

file_put_contents('payday2/assets/js/payday2.js', $js);
