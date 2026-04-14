<?php
$content = file_get_contents('payday2/view.php');
$content = str_replace('<link rel="stylesheet" href="/assets/css/payday_index.css?v=20260414_0100">', '<link rel="stylesheet" href="/assets/css/payday_index.css?v=20260414_0100">' . "\n" . '  <link rel="stylesheet" href="/payday2/assets/css/payday2.css?v=' . time() . '">', $content);
file_put_contents('payday2/view.php', $content);
