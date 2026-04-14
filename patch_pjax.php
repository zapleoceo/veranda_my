<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

// 1. Replace the top IIFE with a function
$js = preg_replace('/^\(\(\) => \{/m', 'window.initPayday2 = function() {', $js, 1);

// 2. Replace the bottom IIFE execution
$js = preg_replace('/\}\)\(\);$/m', "};\nwindow.initPayday2();", $js, 1);

// 3. Replace window.location.href = window.location.href with doPjax
$js = preg_replace('/window\.location\.href = window\.location\.href;/', 'if(window.doPjax) window.doPjax(window.location.href); else window.location.reload();', $js);

// 4. Also replace location.href assignments
$js = preg_replace('/location\.href = ([^;]+);/', 'if(window.doPjax) window.doPjax($1); else location.href = $1;', $js);
$js = preg_replace('/window\.location\.href = ([^;]+);/', 'if(window.doPjax) window.doPjax($1); else window.location.href = $1;', $js);

file_put_contents('payday2/assets/js/payday2.js', $js);
