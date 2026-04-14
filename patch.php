<?php
$content = file_get_contents('payday/index.php');

$search1 = <<<'HTML'
                                  <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                      <thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Комментарий</th></tr></thead>
                                      <tbody>
                                      <?php foreach ($vietnamFound as $f): ?>
                                          <?php
                                              $ts = (int)($f['ts'] ?? 0);
                                              $sumMinor = (int)($f['sum_minor'] ?? 0);
                                              $sumVnd = (int)$posterCentsToVnd($sumMinor);
                                              $tRaw = (string)($f['type'] ?? '');
                                              $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
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
                                  <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                      <thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Счет</th><th style="padding:2px 4px;">Кто</th><th style="padding:2px 4px;">Комментарий</th></tr></thead>
                                      <tbody>
                                      <?php foreach ($vietnamFound as $f): ?>
                                          <?php
                                              $ts = (int)($f['ts'] ?? 0);
                                              $sumMinor = (int)($f['sum_minor'] ?? 0);
                                              $sumVnd = (int)$posterCentsToVnd($sumMinor);
                                              $tRaw = (string)($f['type'] ?? '');
                                              $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
                                              $sumSignedVnd = $isOut ? -$sumVnd : $sumVnd;
                                              $cmt = trim((string)($f['comment'] ?? ''));
                                              $u = trim((string)($f['user'] ?? ''));
                                              $accId = (int)($f['account_id'] ?? $f['account'] ?? 0);
                                              $accName = isset($accountsMapFinance[$accId]) ? $accountsMapFinance[$accId] : ('#' . $accId);
                                              $dateStr = date('d.m.Y', $ts);
                                              $timeStr = date('H:i:s', $ts);
                                          ?>
                                          <tr>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                              <td class="sum" style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($accName) ?></td>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($u) ?></td>
                                              <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($cmt) ?></td>
                                          </tr>
HTML;

$search2 = <<<'HTML'
                                  <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                      <thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Комментарий</th></tr></thead>
                                      <tbody>
                                      <?php foreach ($tipsFound as $f): ?>
                                          <?php
                                              $ts = (int)($f['ts'] ?? 0);
                                              $sumMinor = (int)($f['sum_minor'] ?? 0);
                                              $sumVnd = (int)$posterCentsToVnd($sumMinor);
                                              $tRaw = (string)($f['type'] ?? '');
                                              $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
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

$replace2 = <<<'HTML'
                                  <table class="table" style="margin-top:5px; font-size:12px; width:100%;">
                                      <thead><tr><th style="padding:2px 4px;">Дата<br><span style="font-weight:normal;">Время</span></th><th style="padding:2px 4px;">Сумма</th><th style="padding:2px 4px;">Счет</th><th style="padding:2px 4px;">Кто</th><th style="padding:2px 4px;">Комментарий</th></tr></thead>
                                      <tbody>
                                      <?php foreach ($tipsFound as $f): ?>
                                          <?php
                                              $ts = (int)($f['ts'] ?? 0);
                                              $sumMinor = (int)($f['sum_minor'] ?? 0);
                                              $sumVnd = (int)$posterCentsToVnd($sumMinor);
                                              $tRaw = (string)($f['type'] ?? '');
                                              $isOut = ($tRaw === '0' || strtoupper($tRaw) === 'O' || strtolower($tRaw) === 'out');
                                              $sumSignedVnd = $isOut ? -$sumVnd : $sumVnd;
                                              $cmt = trim((string)($f['comment'] ?? ''));
                                              $u = trim((string)($f['user'] ?? ''));
                                              $accId = (int)($f['account_id'] ?? $f['account'] ?? 0);
                                              $accName = isset($accountsMapFinance[$accId]) ? $accountsMapFinance[$accId] : ('#' . $accId);
                                              $dateStr = date('d.m.Y', $ts);
                                              $timeStr = date('H:i:s', $ts);
                                          ?>
                                          <tr>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($dateStr) ?><br><span class="muted"><?= htmlspecialchars($timeStr) ?></span></td>
                                              <td class="sum" style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($fmtVnd((int)$sumSignedVnd)) ?></td>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($accName) ?></td>
                                              <td style="padding:2px 4px; white-space:nowrap;"><?= htmlspecialchars($u) ?></td>
                                              <td style="padding:2px 4px; line-height:1.2;"><?= htmlspecialchars($cmt) ?></td>
                                          </tr>
HTML;

$content = str_replace($search1, $replace1, $content);
$content = str_replace($search2, $replace2, $content);
file_put_contents('payday/index.php', $content);
