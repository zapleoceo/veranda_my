<?php
$php = file_get_contents('payday2/ajax.php');

$loggerPattern = '/if \(\$kind === \'vietnam\'\) \{.*?file_put_contents\(\$logFile, \$logStr\);\n\s*\}/s';
$php = preg_replace($loggerPattern, '', $php);

$php = str_replace("'debug_raw_count' => count(\$rows), 'debug_dFrom' => \$dFrom", "", $php);
$php = str_replace(",  ]", "]", $php);

file_put_contents('payday2/ajax.php', $php);
