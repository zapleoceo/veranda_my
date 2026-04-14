<?php
$c = file_get_contents("payday/index.php");

$lines = explode("\n", $c);
$out = [];
for ($i = 0; $i < count($lines); $i++) {
    $l = $lines[$i];
    if (strpos($l, '$commentText = $u !== \'\' ? "$cmt ($u)" : $cmt;') !== false) {
        $out[] = '                                              $acc = trim((string)($f[\'account\'] ?? \'\'));';
    } elseif (strpos($l, '<td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($commentText) ?></td>') !== false) {
        $out[] = '                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($acc) ?></td>';
        $out[] = '                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($u) ?></td>';
        $out[] = '                                              <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($cmt) ?></td>';
    } else {
        $out[] = $l;
    }
}

file_put_contents("payday/index.php", implode("\n", $out));
