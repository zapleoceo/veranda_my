<?php
$html = file_get_contents('payday2/view.php');
$startStr = '$transferVietnamExists = false;';
$endStr = '$posterAccounts = [];';
$pos1 = strpos($html, $startStr);
$pos2 = strpos($html, $endStr, $pos1);

if ($pos1 !== false && $pos2 !== false) {
    $html = substr_replace($html, '', $pos1, $pos2 - $pos1);
    file_put_contents('payday2/view.php', $html);
}
