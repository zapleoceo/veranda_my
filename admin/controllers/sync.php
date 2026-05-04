<?php

require_once __DIR__ . '/../sections/sync/section.php';

$state = admin_sync_section_state($db);
$metaTable = $state['metaTable'];
$syncDefs = $state['syncDefs'];
$meta = $state['meta'];
$canExec = $state['canExec'];
$phpBin = $state['phpBin'];
$runResultHtml = $state['runResultHtml'];

