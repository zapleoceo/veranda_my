<?php
$qs = $_SERVER['QUERY_STRING'] ?? '';
$url = '/admin/';
if ($qs !== '') {
    $url .= '?' . $qs;
}
header('Location: ' . $url, true, 301);
exit;
