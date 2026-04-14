<?php
$html = file_get_contents('payday2/view.php');

// Remove PHP fetch block
$startStr = '$transferVietnamExists = false;';
$endStr = '$posterAccounts = [];';
$pos1 = strpos($html, $startStr);
$pos2 = strpos($html, $endStr, $pos1);
if ($pos1 !== false && $pos2 !== false) {
    $html = substr_replace($html, '', $pos1, $pos2 - $pos1);
}

// Remove the vietnamFound and tipsFound loops
$startStr = '$vietnamFound = [];';
$endStr = '$vietnamExists = count($vietnamFound) > 0;';
$pos1 = strpos($html, $startStr);
$pos2 = strpos($html, $endStr, $pos1);
if ($pos1 !== false && $pos2 !== false) {
    $html = substr_replace($html, '', $pos1, $pos2 - $pos1);
}

// Replace the existence flags
$html = str_replace('$vietnamExists = count($vietnamFound) > 0;', '$vietnamExists = false;', $html);
$html = str_replace('$tipsExists = count($tipsFound) > 0;', '$tipsExists = false;', $html);

// Remove the HTML tables and just render "Загрузка..."
$startStr = '<div class="muted finance-status" style="margin-top: 6px;">';
$endStr = '</div>
                  </form>
              </div>

              <div class="finance-row">
                  <form method="POST" class="finance-transfer"';
$pos1 = strpos($html, $startStr);
$pos2 = strpos($html, $endStr, $pos1);
if ($pos1 !== false && $pos2 !== false) {
    $html = substr_replace($html, '<div class="muted finance-status" style="margin-top: 6px;"><span style="color:var(--muted);">Загрузка...</span>', $pos1, $pos2 - $pos1);
}

// Do the same for tips
$startStr2 = '<div class="muted finance-status" style="margin-top: 6px;">';
$endStr2 = '</div>
                  </form>
              </div>
          </div>
      </div>
  </div>';
$pos3 = strpos($html, $startStr2, $pos2); // search after the first one
$pos4 = strpos($html, $endStr2, $pos3);
if ($pos3 !== false && $pos4 !== false) {
    $html = substr_replace($html, '<div class="muted finance-status" style="margin-top: 6px;"><span style="color:var(--muted);">Загрузка...</span>', $pos3, $pos4 - $pos3);
}

file_put_contents('payday2/view.php', $html);
