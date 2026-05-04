<?php

function admin_sync_section_state(\App\Classes\Database $db): array
{
    $metaTable = $db->t('system_meta');

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

    $disabled = strtolower((string)ini_get('disable_functions'));
    $canExec = function_exists('exec') && ($disabled === '' || strpos($disabled, 'exec') === false);
    $phpBin = (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') ? PHP_BINARY : 'php';

    $runResultHtml = '';
    if (isset($_POST['run_script'])) {
        $script = (string)($_POST['script_name'] ?? '');
        $dateFrom = (string)($_POST['date_from'] ?? date('Y-m-d'));
        $dateTo = (string)($_POST['date_to'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = date('Y-m-d');

        $cmd = null;
        $isBackground = false;
        if ($script === 'kitchen_cron') {
            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../../cron.php');
        } elseif ($script === 'kitchen_resync_range') {
            $jobId = date('Ymd_His');
            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../../scripts/kitchen/resync_range.php') . ' ' . escapeshellarg($dateFrom) . ' ' . escapeshellarg($dateTo) . ' ' . escapeshellarg($jobId);
            $isBackground = true;
        } elseif ($script === 'kitchen_prob_close') {
            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../../scripts/kitchen/backfill_prob_close_at.php');
        } elseif ($script === 'menu_cron') {
            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../../menu_cron.php');
        } elseif ($script === 'tg_alerts') {
            $cmd = $phpBin . ' ' . escapeshellarg(__DIR__ . '/../../../telegram_alerts.php');
        }

        if (!$canExec) {
            $runResultHtml = '<div class="error" style="margin-top:12px;">exec() отключён — запустить нельзя.</div>';
        } elseif ($cmd) {
            $out = [];
            $code = 0;
            if ($isBackground) {
                $logFile = __DIR__ . '/../../../resync_range.log';
                exec($cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!', $out, $code);
            } else {
                exec($cmd . ' 2>&1', $out, $code);
            }
            if (count($out) > 200) $out = array_slice($out, -200);
            $runResultHtml = '<pre style="margin-top:12px; white-space:pre-wrap; word-break:break-word; background:var(--card); color:var(--text); padding:12px; border-radius:12px; overflow:auto; max-height:360px;">'
                . htmlspecialchars("exit={$code}\n" . implode("\n", $out))
                . '</pre>';
        } else {
            $runResultHtml = '<div class="error">Неизвестный скрипт</div>';
        }
    }

    return [
        'metaTable' => $metaTable,
        'syncDefs' => $syncDefs,
        'meta' => $meta,
        'canExec' => $canExec,
        'phpBin' => $phpBin,
        'runResultHtml' => $runResultHtml,
    ];
}

