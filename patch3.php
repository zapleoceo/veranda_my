<?php
$content = file_get_contents('payday/index.php');

$search1 = <<<'HTML'
                                              $sumSignedVnd = $isOut ? -$sumVnd : $sumVnd;
                                              $cmt = trim((string)($f['comment'] ?? ''));
                                              $u = trim((string)($f['user'] ?? ''));
                                              $commentText = $u !== '' ? "$cmt ($u)" : $cmt;
                                              $dateStr = date('d.m.Y', $ts);
                                              $timeStr = date('H:i:s', $ts);
                                          ?>
                                          <tr>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                              <td class="sum" style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                              <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($commentText) ?></td>
                                          </tr>
HTML;

$replace1 = <<<'HTML'
                                              $sumSignedVnd = $isOut ? -$sumVnd : $sumVnd;
                                              $cmt = trim((string)($f['comment'] ?? ''));
                                              $u = trim((string)($f['user'] ?? ''));
                                              $acc = trim((string)($f['account'] ?? ''));
                                              $dateStr = date('d.m.Y', $ts);
                                              $timeStr = date('H:i:s', $ts);
                                          ?>
                                          <tr>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                              <td class="sum" style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($acc) ?></td>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($u) ?></td>
                                              <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($cmt) ?></td>
                                          </tr>
HTML;

$content = str_replace($search1, $replace1, $content);
file_put_contents('payday/index.php', $content);
