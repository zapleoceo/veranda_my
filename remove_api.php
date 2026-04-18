<?php
$content = file_get_contents('/workspace/admin.php');
$start = strpos($content, '    if ($posterToken === \'\') {');
$end = strpos($content, '$isMenuAjax = ($_GET[\'ajax\'] ?? \'\') === \'menu_publish\';');
if ($start !== false && $end !== false) {
    // Find the end of the `if ($tab === 'reservations') {` block, which should be right before `$isMenuAjax`
    // Let's just replace from $start to $end with `}\n\n`
    $content = substr_replace($content, "}\n\n", $start, $end - $start);
    file_put_contents('/workspace/admin.php', $content);
}
