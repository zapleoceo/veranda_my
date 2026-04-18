<?php
$html = file_get_contents('/workspace/admin_dc38ed2.php');
$start = strpos($html, '<?php if ($tab === \'reservations\'): ?>');
$end = strpos($html, '<?php elseif ($tab === \'sync\'): ?>');
$res = trim(substr($html, $start + 40, $end - $start - 40));
file_put_contents('/workspace/admin/views/reservations.php', $res);

$logic = substr($html, 0, strpos($html, '<!DOCTYPE html>'));
$res_start = '$resHallId =';
$res_end = '$isMenuAjax =';
$res_logic = "<?php\n\$usersTable = \$db->t('users');\n\$metaTable = \$db->t('system_meta');\n" . substr($logic, strpos($logic, $res_start), strpos($logic, $res_end) - strpos($logic, $res_start));
file_put_contents('/workspace/admin/controllers/reservations.php', $res_logic);
echo "Reservations extracted from dc38ed2\n";
