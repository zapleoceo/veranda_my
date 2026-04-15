<?php

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$path = __DIR__ . '/../../assets/css/Tr2.css';
$css = is_file($path) ? file_get_contents($path) : '';
echo $css === false ? '' : $css;

