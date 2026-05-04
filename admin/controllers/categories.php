<?php

require_once __DIR__ . '/../sections/menu/section.php';

$state = admin_menu_section_state($db, $posterToken, $tab, $message, $error);
$menuItems = $state['menuItems'];
$menuTotal = $state['menuTotal'];
$menuPerPage = $state['menuPerPage'];
$menuPage = $state['menuPage'];
$menuEdit = $state['menuEdit'];
$menuWorkshops = $state['menuWorkshops'];
$menuCategories = $state['menuCategories'];
$menuSyncMeta = $state['menuSyncMeta'];
$menuSyncAtIso = $state['menuSyncAtIso'];
$menuView = $state['menuView'];
$mainItemCounts = $state['mainItemCounts'];
$stripNumberPrefix = $state['stripNumberPrefix'];

