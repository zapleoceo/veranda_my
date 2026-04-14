<?php
$js = file_get_contents('payday2/assets/js/payday2.js');
$js = str_replace("if (options.method !== 'POST') {\n                window.history.pushState({}, '', url);\n            }", "if (options.method !== 'POST' || res.redirected) {\n                window.history.pushState({}, '', url);\n            }", $js);
file_put_contents('payday2/assets/js/payday2.js', $js);
