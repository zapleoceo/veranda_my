<?php

require_once __DIR__ . '/../sections/logs/section.php';

$state = admin_logs_section_state($message);
$view = $state['view'];
$lines = $state['lines'];
$logMap = $state['logMap'];
$path = $state['path'];
$content = $state['content'];
$syncJobs = $state['syncJobs'];
$fileInfo = $state['fileInfo'];
$fmtSpotMtime = $state['fmtSpotMtime'];
$syncStatus = $state['syncStatus'];
$cronHuman = $state['cronHuman'];

