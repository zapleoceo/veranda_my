<?php
$html = file_get_contents('payday2/view.php');

$start = '<div class="muted finance-status" style="margin-top: 6px;">';
$end = '</div>
                  </form>
              </div>';

$pos1 = strpos($html, $start);
$pos2 = strpos($html, $end, $pos1);

if ($pos1 !== false && $pos2 !== false) {
    $html = substr_replace($html, '<div class="muted finance-status" style="margin-top: 6px;"><span style="color:var(--muted);">Загрузка...</span></div>', $pos1, $pos2 - $pos1);
}

// Next one
$pos3 = strpos($html, $start, $pos1 + 100);
$pos4 = strpos($html, $end, $pos3);
if ($pos3 !== false && $pos4 !== false) {
    $html = substr_replace($html, '<div class="muted finance-status" style="margin-top: 6px;"><span style="color:var(--muted);">Загрузка...</span></div>', $pos3, $pos4 - $pos3);
}

file_put_contents('payday2/view.php', $html);
