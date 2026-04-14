<?php
$php = file_get_contents('payday2/ajax.php');

$pattern = '/\$rows = \[\];\s*try \{\s*\$rows = \$api->request\(\'finance\.getTransactions\', \[\s*\'dateFrom\' => date\(\'Ymd\', \$startTs\),\s*\'dateTo\' => date\(\'Ymd\', \$endTs\),\s*\'timezone\' => \'client\',\s*\]\);\s*\} catch \(\\\\Throwable \$e\) \{\s*\$rows = \[\];\s*\}/s';

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

$php = preg_replace($pattern, $new, $php);
file_put_contents('payday2/ajax.php', $php);
