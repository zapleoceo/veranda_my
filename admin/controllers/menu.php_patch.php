<?php
$menu = file_get_contents('/workspace/admin/controllers/menu.php');
$vars = "
\$menuItems = [];
\$menuTotal = 0;
\$menuPerPage = 50;
\$menuPage = max(1, (int)(\$_GET['page'] ?? 1));
\$menuEdit = null;
\$menuWorkshops = [];
\$menuCategories = [];
\$menuSyncMeta = ['last_sync_at' => null, 'last_sync_result' => null, 'last_sync_error' => null];
\$menuSyncAtIso = '';
";
$menu = str_replace("\$menuView = \$_GET['view'] ?? 'list';", $vars . "\$menuView = \$_GET['view'] ?? 'list';", $menu);
file_put_contents('/workspace/admin/controllers/menu.php', $menu);
