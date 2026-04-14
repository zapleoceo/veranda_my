<?php
$php = file_get_contents('payday2/ajax.php');

// Change dmY to Ymd in refresh_finance_transfers
$php = str_replace(
    "'dateFrom' => date('dmY', \$startTs),",
    "'dateFrom' => date('Ymd', \$startTs),",
    $php
);
$php = str_replace(
    "'dateTo' => date('dmY', \$endTs),",
    "'dateTo' => date('Ymd', \$endTs),",
    $php
);

file_put_contents('payday2/ajax.php', $php);
