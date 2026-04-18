<?php
                $syncDefs = [
                    [
                        'label' => 'Kitchen sync',
                        'at_key' => 'kitchen_last_sync_at',
                        'result_key' => 'kitchen_last_sync_result',
                        'error_key' => 'kitchen_last_sync_error',
                        'desc' => 'Синхронизирует чеки/позиции кухни из Poster в kitchen_stats. Используется для Kitchen Online, Rawdata и Dashboard.',
                    ],
                    [
                        'label' => 'Telegram alerts',
                        'at_key' => 'telegram_last_run_at',
                        'result_key' => 'telegram_last_run_result',
                        'error_key' => 'telegram_last_run_error',
                        'desc' => 'Отправляет/обновляет уведомления в Telegram по долгим блюдам (по блюду, не по чеку). Удаляет уведомления при готовности/закрытии/игноре.',
                    ],
                    [
                        'label' => 'Kitchen resync job',
                        'at_key' => 'kitchen_resync_job_last_update_at',
                        'result_key' => 'kitchen_resync_job_progress',
                        'error_key' => 'kitchen_resync_job_error',
                        'desc' => 'Фоновый пересинк кухни за диапазон дат. Нужен для пересчёта статистики за периоды без 504 таймаутов.',
                    ],
                    [
                        'label' => 'Menu sync',
                        'at_key' => 'menu_last_sync_at',
                        'result_key' => 'menu_last_sync_result',
                        'error_key' => 'menu_last_sync_error',
                        'desc' => 'Синхронизирует меню из Poster в poster_menu_items и справочники (цехи/категории/позиции) для сайта и админки.',
                    ],
                ];
                $needKeys = [];
                foreach ($syncDefs as $d) {
                    $needKeys[$d['at_key']] = true;
                    $needKeys[$d['result_key']] = true;
                    $needKeys[$d['error_key']] = true;
                }
                $meta = [];
                foreach (array_keys($needKeys) as $k) {
                    $row = $db->query("SELECT meta_value FROM {$metaTable} WHERE meta_key = ? LIMIT 1", [$k])->fetch();
                    $meta[$k] = $row ? (string)$row['meta_value'] : '';
                }
            ?>
            <div class="card">
                <h3>Статус синков</h3>
                <table class="menu-table">
                    <tr>
                        <th>Синк</th>
                        <th>Последний запуск</th>
                        <th>Результат</th>
                        <th>Ошибка</th>
                        <th>Описание</th>
                    </tr>
                    <?php foreach ($syncDefs as $d): ?>
                        <tr>
                            <td style="font-weight:700;"><?= htmlspecialchars($d['label']) ?></td>
                            <td><?= htmlspecialchars($meta[$d['at_key']] !== '' ? $meta[$d['at_key']] : '—') ?></td>
                            <td><?= htmlspecialchars($meta[$d['result_key']] !== '' ? $meta[$d['result_key']] : '—') ?></td>
                            <td><?= htmlspecialchars($meta[$d['error_key']] !== '' ? $meta[$d['error_key']] : '—') ?></td>
                            <td class="muted"><?= htmlspecialchars($d['desc']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="card">
                <h3>Запуск руками</h3>
                <div class="muted">Запускает серверные скрипты. Рекомендуется использовать редко и осознанно.</div>
                <?php
                    $disabled = strtolower((string)ini_get('disable_functions'));
                    $canExec = function_exists('exec') && ($disabled === '' || strpos($disabled, 'exec') === false);
                    $phpBin = (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') ? PHP_BINARY : 'php';
                ?>
                <form method="post" style="margin-top: 12px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                    <input type="hidden" name="run_script" value="1">
                    <div class="form-group" style="flex:1; min-width:220px; margin-bottom:0;">
                        <label for="script_name">Скрипт</label>
                        <select name="script_name" id="script_name">
                            <option value="kitchen_cron" data-desc="cron.php — синк кухни за сегодня (kitchen_stats), обновляет Kitchen Online / Dashboard / Rawdata.">Кухня: синк за сегодня</option>
                            <option value="kitchen_resync_range" data-desc="scripts/kitchen/resync_range.php — пересинк кухни за диапазон дат (фоновой запуск, чтобы не ловить 504).">Кухня: пересинк диапазон</option>
                            <option value="kitchen_prob_close" data-desc="scripts/kitchen/backfill_prob_close_at.php — пересчёт логического закрытия (ProbCloseTime).">Пересчёт ВрЛогЗакр</option>
                            <option value="menu_cron" data-desc="menu_cron.php — синк меню из Poster (poster_menu_items + справочники).">Меню: синк из Poster</option>
                            <option value="tg_alerts" data-desc="telegram_alerts.php — отправка/обновление Telegram уведомлений.">Telegram: уведомления</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_from">От</label>
                        <input type="date" name="date_from" id="date_from" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="date_to">До</label>
                        <input type="date" name="date_to" id="date_to" value="<?= htmlspecialchars(date('Y-m-d')) ?>">
                    </div>
                    <button type="submit" <?= $canExec ? '' : 'disabled' ?>>Запустить</button>
                </form>
                <?php if (!$canExec): ?>
                    <div class="error" style="margin-top:12px;">Запуск недоступен: на сервере отключена функция exec().</div>
                <?php endif; ?>
                <div id="script_desc" class="muted" style="margin-top:10px;"></div>
                <?php
                    if (isset($_POST['run_script'])) {
                        $script = (string)($_POST['script_name'] ?? '');
                        $dateFrom = (string)($_POST['date_from'] ?? date('Y-m-d'));
                        $dateTo = (string)($_POST['date_to'] ?? date('Y-m-d'));
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d');

                        $cmd = null;
                        $isBackground = false;
                        if ($script === 'kitchen_cron') {
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../cron.php');
                        } elseif ($script === 'kitchen_resync_range') {
                            $jobId = date('Ymd_His');
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../scripts/kitchen/resync_range.php') . ' ' . escapeshellarg($dateFrom) . ' ' . escapeshellarg($dateTo) . ' ' . escapeshellarg($jobId);
                            $isBackground = true;
                        } elseif ($script === 'kitchen_prob_close') {
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../scripts/kitchen/backfill_prob_close_at.php');
                        } elseif ($script === 'menu_cron') {
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../menu_cron.php');
                        } elseif ($script === 'tg_alerts') {
                            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../telegram_alerts.php');
                        }

                        if (!$canExec) {
                            echo '<div class="error" style="margin-top:12px;">exec() отключён — запустить нельзя.</div>';
                        } elseif ($cmd) {
                            $out = [];
                            $code = 0;
                            if ($isBackground) {
                                $logFile = __DIR__ . '/../../resync_range.log';
                                exec($cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!', $out, $code);
                            } else {
                                exec($cmd . ' 2>&1', $out, $code);
                            }
                            if (count($out) > 200) $out = array_slice($out, -200);
                            echo '<pre style="margin-top:12px; white-space:pre-wrap; word-break:break-word; background:var(--card); color:var(--text); padding:12px; border-radius:12px; overflow:auto; max-height:360px;">' . htmlspecialchars("exit={$code}\n" . implode("\n", $out)) . '</pre>';
                        } else {
                            echo '<div class="error">Неизвестный скрипт</div>';
                        }
                    }
                ?>
                <script src="/assets/js/admin_2.js"></script>
            </div>