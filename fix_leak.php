<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$replacements = [
    "document.addEventListener('change', (ev) => {" => "if(window._hChange) document.removeEventListener('change', window._hChange);\n    window._hChange = (ev) => {",
    "document.addEventListener('click', (ev) => {" => "if(window._hClick) document.removeEventListener('click', window._hClick);\n    window._hClick = (ev) => {",
    "document.addEventListener('keydown', (e) => {" => "if(window._hKd) document.removeEventListener('keydown', window._hKd);\n    window._hKd = (e) => {",
    
    // And we need to close the assignment and add the listener.
    // Actually, replacing `});` at the end of these blocks is too hard via regex.
    // It's much safer to just define the function and pass it:
];

// Let's do it manually using regex matching the blocks.
$js = preg_replace("/document\.addEventListener\('change',\s*\(ev\)\s*=>\s*\{/", "if(window._hChange) document.removeEventListener('change', window._hChange);\n    window._hChange = (ev) => {", $js, 1);
// Wait, the block ends with `    });`
// We can just add `document.addEventListener('change', window._hChange);` right after it!
$js = preg_replace("/(\s*updateOutSelection\(\);\n\s*\};\n)(?!.*window\._hChange)/s", "$1    document.addEventListener('change', window._hChange);\n", $js, 1);

// Now for the click event
$js = preg_replace("/document\.addEventListener\('click',\s*\(ev\)\s*=>\s*\{/", "if(window._hClick) document.removeEventListener('click', window._hClick);\n    window._hClick = (ev) => {", $js, 1);
// Click event ends near line 1600 (it's huge)
