<?php
$php = file_get_contents('payday2/ajax.php');

$logger = <<<PHP
        if (\$kind === 'vietnam') {
            \$logFile = __DIR__ . '/debug_finance.log';
            \$logStr = "=== FINANCE REFRESH ===\nDate: \$dFrom to \$dTo\n";
            \$logStr .= "Rows fetched: " . count(\$rows) . "\n";
            foreach (\$rows as \$r) {
                \$tRaw = (string)(\$r['type'] ?? '');
                \$dRaw = \$r['date'] ?? null;
                \$cmt = (string)(\$r['comment'] ?? '');
                \$logStr .= "Row: ID " . (\$r['transaction_id'] ?? '?') . " | Type: \$tRaw | Date: \$dRaw | Amount: " . (\$r['amount'] ?? 0) . " | Cmt: \$cmt\n";
            }
            file_put_contents(\$logFile, \$logStr);
        }
PHP;

$php = str_replace(
    "if (!is_array(\$rows)) \$rows = [];",
    "if (!is_array(\$rows)) \$rows = [];\n" . $logger,
    $php
);

file_put_contents('payday2/ajax.php', $php);
