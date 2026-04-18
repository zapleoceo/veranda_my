<?php
$html = file_get_contents('/workspace/admin_orig.php');
$html = substr($html, strpos($html, '<!DOCTYPE html>'));

function get_between($content, $start, $end) {
    $p1 = strpos($content, $start);
    if ($p1 === false) return '';
    $p2 = strpos($content, $end, $p1);
    if ($p2 === false) $p2 = strlen($content);
    return trim(substr($content, $p1 + strlen($start), $p2 - $p1 - strlen($start)));
}

// 1. RESERVATIONS
$res = get_between($html, '<?php if ($tab === \'reservations\'): ?>', '<?php elseif ($tab === \'sync\'): ?>');
file_put_contents('/workspace/admin/views/reservations.php', $res);

// 2. SYNC
$sync = get_between($html, '<?php elseif ($tab === \'sync\'): ?>', '<?php elseif ($tab === \'telegram\'): ?>');
file_put_contents('/workspace/admin/views/sync.php', $sync);

// 3. TELEGRAM
$tg = get_between($html, '<?php elseif ($tab === \'telegram\'): ?>', '<?php elseif ($tab === \'access\'): ?>');
file_put_contents('/workspace/admin/views/telegram.php', $tg);

// 4. ACCESS
$access = get_between($html, '<?php elseif ($tab === \'access\'): ?>', '<?php else: ?>');
$access = preg_replace('/<script.*?>.*?<\/script>/is', '', $access);
file_put_contents('/workspace/admin/views/access.php', $access);

// 5. MENU & CATEGORIES
$menu_cat = get_between($html, '<?php else: ?>', '<?php endif; ?>
    </div>
    <script src="/assets/js/admin_3.js"></script>');
    
// Split menu and categories
$cat_start = strpos($menu_cat, '<?php if ($menuView === \'categories\'): ?>');
$edit_start = strpos($menu_cat, '<?php elseif ($menuView === \'edit\'): ?>');
$list_start = strpos($menu_cat, '<?php else: ?>', $edit_start);

$top_part = substr($menu_cat, 0, $cat_start);
$categories = substr($menu_cat, $cat_start + 45, $edit_start - $cat_start - 45);
$edit = substr($menu_cat, $edit_start + 41, $list_start - $edit_start - 41);
$list = substr($menu_cat, $list_start + 14);

$categories_html = $top_part . $categories;
$menu_html = $top_part . "<?php if (\$menuView === 'edit'): ?>\n" . $edit . "\n<?php else: ?>\n" . $list . "\n<?php endif; ?>";

$menu_html = preg_replace('/<script>.*?<\/script>/is', '', $menu_html);

file_put_contents('/workspace/admin/views/categories.php', trim($categories_html));
file_put_contents('/workspace/admin/views/menu.php', trim($menu_html));

echo "Views extracted correctly\n";
