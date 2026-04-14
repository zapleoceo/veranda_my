<?php
$php = file_get_contents('payday2/ajax.php');
$php = str_replace(
    "} catch (\Throwable \$e) {\n            \$rows = [];",
    "} catch (\Throwable \$e) {\n            file_put_contents(__DIR__.'/debug.log', \$e->getMessage());\n            \$rows = [];",
    $php
);
file_put_contents('payday2/ajax.php', $php);
