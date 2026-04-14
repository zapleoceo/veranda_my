<?php
$js = file_get_contents('payday2/assets/js/payday2.js');

$js = str_replace(
    "document.addEventListener('change', (ev) => {",
    "if (!window._docChange) { window._docChange = true; document.addEventListener('change', (ev) => {",
    $js
);

// We need to close the `if (!window._docChange)` block at the end of the `change` listener.
// Actually, it's safer to just attach the listener inside an IIFE that runs once.
// Instead of matching the end bracket, we can just replace the event listener attachment 
// to use a named function, or just use the `if(!window.X)` trick and NOT close it (meaning the WHOLE REST OF THE FILE is wrapped?! No, that's bad).
