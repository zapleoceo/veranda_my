<?php
$php = file_get_contents('payday2/ajax.php');

$old = <<<PHP
        \$rows = [];
        try {
            \$rows = \$api->request('finance.getTransactions', [
                'dateFrom' => date('Ymd', \$startTs),
                'dateTo' => date('Ymd', \$endTs),

                'timezone' => 'client',
            ]);
        } catch (\Throwable \$e) {
            \$rows = [];
        }
PHP;

$new = <<<PHP
        \$rows = [];
        try {
            \$rows = \$api->request('finance.getTransactions', [
                'dateFrom' => date('Ymd', \$startTs),
                'dateTo' => date('Ymd', \$endTs),
                'timezone' => 'client',
            ]);
        } catch (\Throwable \$e) {
            \$rows = [];
        }
        if (!is_array(\$rows) || count(\$rows) === 0) {
            try {
                \$rows = \$api->request('finance.getTransactions', [
                    'dateFrom' => date('dmY', \$startTs),
                    'dateTo' => date('dmY', \$endTs),
                    'timezone' => 'client',
                ]);
            } catch (\Throwable \$e) {
                \$rows = [];
            }
        }
PHP;

$php = str_replace($old, $new, $php);
file_put_contents('payday2/ajax.php', $php);
