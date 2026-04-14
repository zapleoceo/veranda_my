<?php
$php = file_get_contents('payday2/ajax.php');
$php = str_replace(
    "echo json_encode(['ok' => true, 'rows' => \$out], JSON_UNESCAPED_UNICODE);",
    "echo json_encode(['ok' => true, 'rows' => \$out, 'debug_raw_count' => count(\$rows), 'debug_dFrom' => \$dFrom], JSON_UNESCAPED_UNICODE);",
    $php
);
file_put_contents('payday2/ajax.php', $php);
