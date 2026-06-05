<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database;
use App\Infrastructure\Logger;
use App\Infrastructure\TelegramBotClient;
use App\Repositories\AlertItemRepository;
use App\Repositories\MetaRepository;

class TelegramAlertService
{
    private const EDIT_COOLDOWN_SECONDS = 60;
    private const STATUS_LOCK_NAME      = 'tg_status_msg';
    private const STATUS_MSG_HISTORY    = 10;
    private const RUN_LOCK_NAME         = 'tg_alerts_run';

    // Бот должен быть админом группы с permission `delete_messages`.
    // В этом режиме Telegram'овское 48-часовое окно на удаление своих
    // сообщений не применяется, поэтому раньше живший здесь
    // BOT_MUTATION_WINDOW_SECONDS / _isPastMutationWindow / deferred-ветка
    // удалены — это был мёртвый код. Если когда-то бота снимут с админов
    // — это регресс настройки группы, а не работа кода.

    public function __construct(
        private readonly Database            $db,
        private readonly TelegramBotClient   $bot,
        private readonly MetaRepository      $meta,
        private readonly AlertItemRepository $alertItems,
        private readonly int|null            $threadId = null,
    ) {}

    public function run(): void
    {
        // Whole-run lock so two crons started inside the same minute don't
        // race on tg_alert_items (would otherwise double-send or double-edit).
        // GET_LOCK(... ,0) is non-blocking — overlapping tick just no-ops.
        // The lock is session-scoped; if PHP dies before RELEASE_LOCK fires,
        // MySQL auto-releases when the connection closes.
        $haveLock = (int) $this->db
            ->query("SELECT GET_LOCK(?, 0) AS l", [self::RUN_LOCK_NAME])
            ->fetchColumn();
        if ($haveLock !== 1) {
            Logger::get()->info('telegram_alerts.skipped_locked');
            return;
        }

        try {
            $startedAt = microtime(true);
            $today     = date('Y-m-d');
            $nowTs     = time();
            $now       = date('Y-m-d H:i:s', $nowTs);

            $settings  = $this->_loadSettings();
            $metrics   = $this->_calculateMetrics($today, $settings);
            $this->_updateStatusMessage($metrics, $now);

            $cutoff  = date('Y-m-d H:i:s', strtotime("-{$metrics->waitLimitMinutes} minutes"));
            $rows    = $this->_fetchOverdueRows($cutoff, $settings);
            $stats   = $this->_processItems($rows, $now, $nowTs);

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->_writeRunMeta($today, $durationMs, $metrics, $stats);

            Logger::get()->info('telegram_alerts.done', [
                'duration_ms' => $durationMs,
                'open'        => $metrics->openChecksDisplay,
                'wait'        => $metrics->waitLimitMinutes,
                'sent'        => $stats['sent'],
                'edited'      => $stats['edited'],
                'deleted'     => $stats['deleted'],
                'unchanged'   => $stats['unchanged'],
            ]);
        } finally {
            try { $this->db->query("SELECT RELEASE_LOCK(?)", [self::RUN_LOCK_NAME]); } catch (\Throwable) {}
        }
    }

    /**
     * Builds the WHERE-fragment that excludes ignored/auto-excluded items.
     * Shared between metrics counting and overdue-row fetching so the
     * "Долгих блюд" counter on the status message always matches the actual
     * alerts in the chat. Previously _fetchOverdueRows hardcoded one variant
     * which silently disagreed with the counter when ko_use_logical_close=0.
     */
    private function _excludeSqlForSettings(\stdClass $s): string
    {
        return $s->ko_use_logical_close
            ? " AND COALESCE(exclude_from_dashboard, 0) = 0 "
            : " AND NOT (COALESCE(exclude_from_dashboard, 0) = 1 AND COALESCE(exclude_auto, 0) = 0) ";
    }

    // ─── settings ────────────────────────────────────────────────────────────

    private function _loadSettings(): \stdClass
    {
        $defaults = [
            'alert_timing_low_load'      => '20',
            'alert_load_threshold'       => '25',
            'alert_timing_high_load'     => '30',
            'exclude_partners_from_load' => '0',
            'ko_use_logical_close'       => '1',
        ];
        // Поля где `0` — валидный смысл (булевы переключатели).
        // Для них пустое значение остаётся `0`, не fallback на дефолт.
        $allowZero = ['exclude_partners_from_load', 'ko_use_logical_close'];

        $values = $this->meta->getMany(array_keys($defaults));

        $s = new \stdClass();
        foreach ($defaults as $key => $default) {
            $raw = $values[$key] ?? $default;
            $v   = (int) $raw;
            // Защита от случайно записанной пустой строки в meta:
            // (int)'' = 0, а 0 у timing-полей означает cutoff = now, что
            // мгновенно делает все свежие блюда «overdue» → шквал
            // алертов. Для тайминговых полей трактуем `<= 0` как
            // «дефолт». Булевы переключатели проходят как есть.
            if ($v <= 0 && !in_array($key, $allowZero, true)) {
                $v = (int) $default;
            }
            $s->$key = $v;
        }
        return $s;
    }

    // ─── metrics ─────────────────────────────────────────────────────────────

    private function _calculateMetrics(string $today, \stdClass $s): \stdClass
    {
        $ks         = $this->db->t('kitchen_stats');
        $excludeSql = $this->_excludeSqlForSettings($s);

        // Open checks / load count
        if ($s->exclude_partners_from_load) {
            $other   = (int) $this->db->query(
                "SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks}
                 WHERE status = 1 AND transaction_date = ? AND table_number != 'Partners'",
                [$today]
            )->fetchColumn();
            $partners = (int) $this->db->query(
                "SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks}
                 WHERE status = 1 AND transaction_date = ? AND table_number = 'Partners'",
                [$today]
            )->fetchColumn();
            $loadCount          = $other;
            $openChecksDisplay  = "{$other}+{$partners}";
        } else {
            $loadCount         = (int) $this->db->query(
                "SELECT COUNT(DISTINCT transaction_id) as c FROM {$ks}
                 WHERE status = 1 AND transaction_date = ?",
                [$today]
            )->fetchColumn();
            $openChecksDisplay = (string) $loadCount;
        }

        $waitLimit = $loadCount < $s->alert_load_threshold
            ? $s->alert_timing_low_load
            : $s->alert_timing_high_load;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$waitLimit} minutes"));

        // Queue and overdue by station
        $baseWhere = "ready_pressed_at IS NULL AND ticket_sent_at IS NOT NULL
                      AND transaction_date = ? AND status = 1
                      AND COALESCE(was_deleted, 0) = 0 {$excludeSql}
                      AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)";

        [$queueBar, $queueKitchen] = $this->_countByStation(
            "SELECT station, COUNT(*) as cnt FROM {$ks} WHERE {$baseWhere} GROUP BY station",
            [$today]
        );
        [$overdueBar, $overdueKitchen] = $this->_countByStation(
            "SELECT station, COUNT(*) as cnt FROM {$ks} WHERE {$baseWhere} AND ticket_sent_at < ? GROUP BY station",
            [$today, $cutoff]
        );

        $m = new \stdClass();
        $m->openChecksDisplay  = $openChecksDisplay;
        $m->waitLimitMinutes   = $waitLimit;
        $m->queueBar           = $queueBar;
        $m->queueKitchen       = $queueKitchen;
        $m->overdueBar         = $overdueBar;
        $m->overdueKitchen     = $overdueKitchen;
        // «В тайминге» — неготовые блюда, ещё НЕ просроченные (очередь минус
        // долгие). overdue — строгое подмножество queue (тот же baseWhere +
        // ticket_sent_at < cutoff), поэтому разница всегда >= 0; max(0,…)
        // только страховка от микро-гонки между двумя SELECT'ами.
        $m->timingBar          = max(0, $queueBar - $overdueBar);
        $m->timingKitchen      = max(0, $queueKitchen - $overdueKitchen);
        return $m;
    }

    /** Returns [barCount, kitchenCount] from a GROUP BY station query */
    private function _countByStation(string $sql, array $params): array
    {
        $bar = 0;
        $kitchen = 0;
        try {
            $rows = $this->db->query($sql, $params)->fetchAll();
            foreach ($rows as $r) {
                $st = (string) ($r['station'] ?? '');
                $c  = (int) $r['cnt'];
                if ($st === '3' || $st === 'Bar Veranda') {
                    $bar += $c;
                } else {
                    $kitchen += $c;
                }
            }
        } catch (\Throwable) {}
        return [$bar, $kitchen];
    }

    // ─── status message ───────────────────────────────────────────────────────

    private function _updateStatusMessage(\stdClass $m, string $now): void
    {
        $haveLock = (int) $this->db->query("SELECT GET_LOCK(?, 0) AS l", [self::STATUS_LOCK_NAME])->fetchColumn();
        if ($haveLock !== 1) {
            return;
        }
        try {

            $lastSync   = $this->meta->get('poster_last_sync_at', $now);
            $srvTag     = trim((string) (php_uname('n') ?: ''));

            // Daily ignore counter (midnight-to-midnight, spot-local TZ).
            // PHP's default tz is set by the cron entry-point to spot TZ, so
            // date('Y-m-d') here is the right "today" for the manager.
            $today      = date('Y-m-d');
            $tomorrow   = date('Y-m-d', strtotime('+1 day'));
            $ignores    = \App\Actions\IgnoreLog::countBetween(
                $this->db,
                $today    . ' 00:00:00',
                $tomorrow . ' 00:00:00'
            );

            // Auto-closed dishes today: items that the kitchen NEVER pressed
            // ready on, but the system auto-excluded because the parent
            // receipt got closed (setClose / setProb rules in
            // KitchenSyncService::_applyAutoExclude). Hookah (cat 47) is
            // excluded — its exclude_auto=1 comes from a different rule and
            // shouldn't pad this counter.
            $autoClosed = (int) $this->db->query(
                "SELECT COUNT(*) FROM {$this->db->t('kitchen_stats')}
                 WHERE transaction_date = ?
                   AND exclude_auto = 1
                   AND ready_pressed_at IS NULL
                   AND COALESCE(was_deleted, 0) = 0
                   AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)",
                [$today]
            )->fetchColumn();

            $statusText = "Открыто чеков: {$m->openChecksDisplay}\n"
                . "Лимит времени: {$m->waitLimitMinutes} мин\n"
                . "В очереди: 🍸{$m->queueBar} / 🍔{$m->queueKitchen}\n"
                . "Долгих блюд: 🍸{$m->overdueBar} / 🍔{$m->overdueKitchen}\n"
                . "Время обновления: {$lastSync}\n"
                . "Игноры: {$ignores['items']}|{$ignores['tx']} ⚙️ {$autoClosed}\n"
                . "В тайминге: 🍸{$m->timingBar} / 🍔{$m->timingKitchen}"
                . ($srvTag !== '' ? "\nSrv: {$srvTag}" : '');

            $prevId   = (int) $this->meta->get('telegram_status_msg_id', '0');
            $prevHash = (string) $this->meta->get('telegram_status_msg_hash', '');
            $prevIds  = json_decode($this->meta->get('telegram_status_msg_ids_json', '[]'), true);
            $prevIds  = is_array($prevIds) ? $prevIds : [];

            // Skip the round-trip when the status text hasn't changed at all.
            // Previously every minute we tried to editMessageText → Telegram
            // returned 400 "message is not modified" → code thought edit
            // failed → deleted + re-sent the same message. That visibly
            // flickered the status line in the chat.
            $currentHash = sha1($statusText);
            if ($prevId > 0 && $prevHash === $currentHash) {
                return;
            }

            if ($prevId > 0 && $this->bot->editMessageText($prevId, $statusText)) {
                $currentId = $prevId;
                $this->meta->set('telegram_status_msg_hash', $currentHash);
            } else {
                $currentId = $this->bot->sendMessageGetId($statusText, $this->threadId);
                if ($currentId) {
                    $this->meta->setMany([
                        'telegram_status_msg_id'   => (string) $currentId,
                        'telegram_status_msg_hash' => $currentHash,
                    ]);
                    if ($prevId > 0) {
                        $this->bot->deleteMessage($prevId);
                    }
                } else {
                    $this->meta->setMany([
                        'telegram_status_msg_id'   => '0',
                        'telegram_status_msg_hash' => '',
                    ]);
                    return;
                }
            }

            // Keep a rolling window of recent status message IDs and delete old ones
            $prevIds[] = $currentId;
            $prevIds   = array_values(array_unique(array_map(fn($v) => (int) $v, $prevIds)));
            $prevIds   = array_slice($prevIds, -self::STATUS_MSG_HISTORY);
            $this->meta->set('telegram_status_msg_ids_json', json_encode($prevIds));

            foreach ($prevIds as $id) {
                if ((int) $id !== $currentId) {
                    $this->bot->deleteMessage((int) $id);
                }
            }
        } catch (\Throwable $e) {
            Logger::get()->warning('telegram_alerts.status_fail', ['error' => $e->getMessage()]);
        } finally {
            try { $this->db->query("SELECT RELEASE_LOCK(?)", [self::STATUS_LOCK_NAME]); } catch (\Throwable) {}
        }
    }

    // ─── overdue items ────────────────────────────────────────────────────────

    /**
     * Открытые карточки, у которых ticket_sent_at старше cutoff (overdue).
     *
     * Раньше здесь стояло `transaction_date = $today`. Это связывало
     * жизнь алерта с календарным днём: в полночь карточки прошлого дня
     * выпадали из выборки одновременно со строками tg_alert_items
     * (findByDate тоже фильтровал по today), и сообщения в Telegram
     * оставались сиротами навсегда. Теперь дата в WHERE не участвует.
     *
     * `ticket_sent_at >= now - 7 days` — НЕ бизнес-условие, а защита
     * индекса от full-scan. Open-чек неделю — это уже не «overdue
     * блюдо», а сбой синхронизации; такого в норме нет.
     *
     * Возвращаем transaction_date — нужен для INSERT-пути в upsert
     * (composite PK таблицы), но в самом фильтре больше не участвует.
     */
    private function _fetchOverdueRows(string $cutoff, \stdClass $s): array
    {
        $ks         = $this->db->t('kitchen_stats');
        $excludeSql = $this->_excludeSqlForSettings($s);
        $safeguard  = date('Y-m-d H:i:s', strtotime('-7 days'));
        return $this->db->query(
            "SELECT id, transaction_id, transaction_date, receipt_number, table_number,
                    waiter_name, transaction_comment, dish_name, ticket_sent_at, tg_sent_at
             FROM {$ks}
             WHERE ready_pressed_at IS NULL
               AND ticket_sent_at IS NOT NULL
               AND status = 1
               AND COALESCE(was_deleted, 0) = 0 {$excludeSql}
               AND NOT (COALESCE(dish_category_id, 0) = 47 OR COALESCE(dish_sub_category_id, 0) = 47)
               AND ticket_sent_at >= ?
               AND ticket_sent_at <  ?
             ORDER BY transaction_id ASC, ticket_sent_at ASC, id ASC",
            [$safeguard, $cutoff]
        )->fetchAll();
    }

    private function _processItems(array $rows, string $now, int $nowTs): array
    {
        // ВСЕ живые алерты, без фильтра по дате. Раньше здесь стоял
        // findByDate(today), который в полночь забывал про вчерашние
        // строки tg_alert_items и сообщения оставались сиротами в чате.
        $existing     = $this->alertItems->findAllActive();
        $candidateIds = array_flip(array_filter(array_column($rows, 'id')));

        $sent = $edited = $deleted = $unchanged = 0;

        // ── 1. Снимаем алерты, которые больше не overdue ──────────────
        // (повар нажал готово / чек закрыт в Poster / помечено игнор /
        //  was_deleted=1 — любое условие, выводящее карточку из выборки)
        foreach ($existing as $kid => $item) {
            if (isset($candidateIds[$kid])) {
                continue;
            }
            // Row без message_id остался от незавершённого send_fail
            // прошлого тика — чистим из БД без обращения в Telegram.
            if ($item->messageId === null) {
                $this->alertItems->deleteByKid($kid);
                continue;
            }

            // Бот = админ группы с правом delete_messages, поэтому
            // удаление работает для сообщений любого возраста.
            // Если Telegram всё же ответил false (сообщение уже было
            // удалено руками / редкий 5xx) — всё равно чистим row,
            // чтобы он не висел вечно. Следующий тик не будет
            // повторно стучаться в несуществующее сообщение.
            $okDelete = $this->bot->deleteMessage($item->messageId);
            if ($okDelete) {
                $deleted++;
            }
            $this->alertItems->deleteByKid($kid);
        }

        // ── 2. Отправляем / редактируем актуальные overdue ────────────
        foreach ($rows as $r) {
            $kid  = (int) ($r['id'] ?? 0);
            $txId = (int) ($r['transaction_id'] ?? 0);
            // transaction_date берём из самой kitchen_stats row, а не
            // из today — иначе вновь привяжемся к календарному дню,
            // что только что вылечили в выборке.
            $txDate = trim((string) ($r['transaction_date'] ?? ''));
            if ($kid <= 0 || $txId <= 0 || $txDate === '') {
                continue;
            }

            [$text, $keyboard] = $this->_buildItemMessage($r, $nowTs);
            $hash = sha1($text . '|' . json_encode($keyboard));

            $prev      = $existing[$kid] ?? null;
            $prevMsgId = $prev?->messageId;
            $prevHash  = $prev?->lastTextHash ?? '';

            // Rate-limit edits to once per 60 seconds — UPDATE seen всё
            // равно делаем под правильной датой row'а.
            if ($prevMsgId !== null && $prevHash !== $hash && $prev->lastSeenAt !== '') {
                $age = $nowTs - (int) strtotime($prev->lastSeenAt);
                if ($age < self::EDIT_COOLDOWN_SECONDS) {
                    $this->alertItems->updateSeen($txDate, $kid, $now);
                    $unchanged++;
                    continue;
                }
            }

            if ($prevMsgId !== null && $prevHash === $hash) {
                $this->alertItems->updateSeen($txDate, $kid, $now);
                $unchanged++;
                continue;
            }

            // Try edit first, then send new
            if ($prevMsgId !== null && $this->bot->editMessageText($prevMsgId, $text, $keyboard)) {
                $this->_updateKsMessageMeta($kid, $prevMsgId, $now);
                $this->alertItems->updateHash($txDate, $kid, $txId, $hash, $now);
                $edited++;
            } else {
                if ($prevMsgId !== null) {
                    $this->bot->deleteMessage($prevMsgId);
                }
                $newId = $this->bot->sendMessageWithKeyboard($text, $keyboard, $this->threadId);
                if ($newId !== null) {
                    $this->_updateKsMessageMeta($kid, $newId, $now);
                    $this->alertItems->upsert($txDate, $kid, $txId, $newId, $hash, $now);
                    $sent++;
                } else {
                    Logger::get()->warning('telegram_alerts.send_fail', ['kid' => $kid, 'tx' => $txId]);
                }
            }
        }

        return compact('sent', 'edited', 'deleted', 'unchanged');
    }

    private function _buildItemMessage(array $r, int $nowTs): array
    {
        $receipt = trim((string) ($r['receipt_number'] ?? '')) ?: (string) ($r['transaction_id'] ?? '');
        $table   = trim((string) ($r['table_number']   ?? '')) ?: '—';
        $waiter  = trim((string) ($r['waiter_name']    ?? '')) ?: '—';
        $comment = trim((string) ($r['transaction_comment'] ?? ''));
        $dish    = trim((string) ($r['dish_name']      ?? '')) ?: '—';
        $sentAt  = trim((string) ($r['ticket_sent_at'] ?? ''));

        $sentTs  = $sentAt !== '' ? (int) strtotime($sentAt) : 0;
        $elapsed = $this->_formatElapsed($sentTs > 0 ? max(0, $nowTs - $sentTs) : 0);
        $start   = $sentTs > 0 ? date('H:i:s', $sentTs) : '—';

        $text  = '<b>Чек: ' . htmlspecialchars($receipt) . ' | Стол ' . htmlspecialchars($table) . "</b>\n";
        $text .= 'Офик: ' . htmlspecialchars($waiter);
        if ($comment !== '') {
            $text .= ' <i>' . htmlspecialchars($comment) . '</i>';
        }
        $text .= "\nБлюдо: " . htmlspecialchars($dish) . "\n";
        $text .= 'Старт: <b>' . htmlspecialchars($start) . '</b> Ждет: <b>' . $elapsed . '</b>';

        $kid  = (int) ($r['id'] ?? 0);
        $txId = (int) ($r['transaction_id'] ?? 0);
        $keyboard = [[
            ['text' => 'Игнор❗️',   'callback_data' => 'ignore_item:' . $kid],
            ['text' => 'Игнор Чек‼️', 'callback_data' => 'ignore_tx:'   . $txId],
        ]];

        return [$text, $keyboard];
    }

    private function _formatElapsed(int $seconds): string
    {
        $hh = (int) floor($seconds / 3600);
        $mm = (int) floor(($seconds % 3600) / 60);
        $ss = (int) ($seconds % 60);

        $mmStr = str_pad((string) $mm, 2, '0', STR_PAD_LEFT);
        $ssStr = str_pad((string) $ss, 2, '0', STR_PAD_LEFT);

        return ($hh > 0 ? "{$hh}:{$mmStr}" : $mmStr) . ':' . $ssStr;
    }

    private function _updateKsMessageMeta(int $kid, int $msgId, string $now): void
    {
        $ks = $this->db->t('kitchen_stats');
        try {
            $this->db->query(
                "UPDATE {$ks}
                 SET tg_message_id = ?, tg_sent_at = COALESCE(tg_sent_at, ?), tg_last_edit_at = ?
                 WHERE id = ?",
                [$msgId, $now, $now, $kid]
            );
        } catch (\Throwable) {}
    }

    private function _writeRunMeta(string $today, int $durationMs, \stdClass $m, array $stats): void
    {
        $result = "duration_ms={$durationMs}; open={$m->openChecksDisplay}; wait={$m->waitLimitMinutes}; "
            . "sent={$stats['sent']}; edited={$stats['edited']}; deleted={$stats['deleted']}; unchanged={$stats['unchanged']}";

        $this->meta->setMany([
            'telegram_last_run_at'     => date('Y-m-d H:i:s'),
            'telegram_last_run_result' => $result,
            'telegram_last_run_error'  => '',
        ]);
    }
}
