<?php

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$path = __DIR__ . '/../../assets/js/Tr2.js';
$js = is_file($path) ? file_get_contents($path) : '';
echo $js === false ? '' : $js;

