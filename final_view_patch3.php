<?php
$html = file_get_contents('payday2/view.php');

$pattern = '/<div class="muted finance-status" style="margin-top: 6px;">.*?<\/div>\s*<\/form>/s';
$html = preg_replace($pattern, '<div class="muted finance-status" style="margin-top: 6px;"><span style="color:var(--muted);">Загрузка...</span></div></form>', $html);

file_put_contents('payday2/view.php', $html);
