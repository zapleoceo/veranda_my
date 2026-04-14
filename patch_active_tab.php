<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$old = <<<HTML
    let activeTab = 'in';
HTML;
$new = <<<HTML
    window.activeTab = window.activeTab || 'in';
HTML;
$js = str_replace($old, $new, $js);

$old2 = <<<HTML
        activeTab = inOn ? 'in' : 'out';
HTML;
$new2 = <<<HTML
        window.activeTab = inOn ? 'in' : 'out';
HTML;
$js = str_replace($old2, $new2, $js);

// Replace any remaining `activeTab` with `window.activeTab` where needed
// Actually, inside the function, there are references to `activeTab`.
// We should declare `let activeTab = window.activeTab || 'in';`
// And `activeTab = ...; window.activeTab = activeTab;`
