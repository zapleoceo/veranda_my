<?php
$qs = $_SERVER['QUERY_STRING'] ?? '';
$url = '/admin/?tab=logs';
if ($qs !== '') {
    $url .= '&' . $qs;
}
header('Location: ' . $url, true, 301);
exit;
