<?php
$html = file_get_contents('payday2/view.php');

$startStr = '<div class="confirm-backdrop" id="kashshiftModal"';
$endStr = '<div id="lineLayer"></div>';

$pos1 = strpos($html, $startStr);
$pos2 = strpos($html, $endStr, $pos1);

if ($pos1 !== false && $pos2 !== false) {
    $modals = substr($html, $pos1, $pos2 - $pos1);
    $html = substr_replace($html, '', $pos1, $pos2 - $pos1);
    
    // Put them before the final script tag
    $html = str_replace('<script>', $modals . "\n<script>", $html);
    file_put_contents('payday2/view.php', $html);
    echo "Modals moved!\n";
} else {
    echo "Modals not found!\n";
}
