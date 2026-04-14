<?php
$php = file_get_contents('payday2/ajax.php');

$old = "'dateFrom' => date('dmY', \$startTs),\n                'dateTo' => date('dmY', \$endTs),\n\n                'timezone' => 'client',";
$new = "'dateFrom' => date('Ymd', \$startTs),\n                'dateTo' => date('Ymd', \$endTs),\n                'timezone' => 'client',";

$php = str_replace($old, $new, $php);
file_put_contents('payday2/ajax.php', $php);
