<?php
$html = file_get_contents('payday2/view.php');

// Replace Vietnam part
$start = '<div class="muted finance-status" style="margin-top: 6px;">
                          <?php if ($vietnamExists): ?>';
$end = '<?php endif; ?>
                      </div>';
$pos1 = strpos($html, $start);
$pos2 = strpos($html, $end, $pos1);
if ($pos1 !== false && $pos2 !== false) {
    $replacement = '<div class="muted finance-status" style="margin-top: 6px;">
                          <span style="color:var(--muted);">Загрузка...</span>
                      </div>';
    $html = substr_replace($html, $replacement, $pos1, $pos2 + strlen($end) - $pos1);
}

// Replace Tips part
$start2 = '<div class="muted finance-status" style="margin-top: 6px;">
                          <?php if ($tipsExists): ?>';
$pos3 = strpos($html, $start2);
$pos4 = strpos($html, $end, $pos3);
if ($pos3 !== false && $pos4 !== false) {
    $replacement2 = '<div class="muted finance-status" style="margin-top: 6px;">
                          <span style="color:var(--muted);">Загрузка...</span>
                      </div>';
    $html = substr_replace($html, $replacement2, $pos3, $pos4 + strlen($end) - $pos3);
}

// Remove disabled checks since they are based on PHP exist check
$html = str_replace('<?= $vietnamDisabled ? \'disabled\' : \'\' ?>', '', $html);
$html = str_replace('<?= $tipsDisabled ? \'disabled\' : \'\' ?>', '', $html);

file_put_contents('payday2/view.php', $html);
