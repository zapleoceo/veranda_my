<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

// Remove the old PJAX block (it ends at line 76: "}\nwindow.__USER_EMAIL__ = window.PAYDAY_CONFIG.userEmail;")
// Wait, where does the old PJAX block end?
$js = preg_replace('/if \(!window\._paydayPjaxLoaded\) \{.*?\}\nwindow\.__USER_EMAIL__/s', 'window.__USER_EMAIL__', $js, 1);

// Put the new PJAX block
$pjax = file_get_contents('payday2_pjax.js');

// Add clearPaydayListeners call at the start of initPayday2
$js = preg_replace('/window\.initPayday2 = function\(\) \{/', "window.initPayday2 = function() {\n    if (window.clearPaydayListeners) window.clearPaydayListeners();", $js, 1);

file_put_contents('payday2/assets/js/payday2.js', $pjax . "\n" . $js);
