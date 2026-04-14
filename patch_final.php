<?php
$js = file_get_contents('payday2/assets/js/payday2.js');
$pjax = file_get_contents('payday2_pjax.js');

// 1. Wrap the IIFE into initPayday2
$js = preg_replace('/^\(\(\) => \{/m', "window.initPayday2 = function() {\n    if (window.clearPaydayListeners) window.clearPaydayListeners();\n    window.__USER_EMAIL__ = window.PAYDAY_CONFIG.userEmail;", $js, 1);
$js = preg_replace('/\}\)\(\);$/m', "};\nwindow.initPayday2();", $js, 1);

// 2. Remove window.__USER_EMAIL__ = ... outside the function
$js = preg_replace('/^window\.__USER_EMAIL__ = window\.PAYDAY_CONFIG\.userEmail;\n/m', '', $js, 1);

// 3. Replace location.href assignments
$js = preg_replace('/window\.location\.href = window\.location\.href;/', 'if(window.doPjax) window.doPjax(window.location.href); else window.location.reload();', $js);
$js = preg_replace('/location\.href = ([^;]+);/', 'if(window.doPjax) window.doPjax($1); else location.href = $1;', $js);
$js = preg_replace('/window\.location\.href = ([^;]+);/', 'if(window.doPjax) window.doPjax($1); else window.location.href = $1;', $js);

// 4. Combine
file_put_contents('payday2/assets/js/payday2.js', $pjax . "\n" . $js);
