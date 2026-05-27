<?php

declare(strict_types=1);

/**
 * One-shot maintenance script: deletes Telegram alert messages that the
 * normal telegram_alerts cron can no longer reach.
 *
 * Sources of orphans:
 *   1. tg_alert_items rows whose transaction_date < today — the main cron
 *      only iterates findByDate(today), so past-day rows never get cleaned
 *      up if their tx closed after midnight.
 *   2. kitchen_stats.tg_message_id set but no matching tg_alert_items row
 *      AND the row is no longer "active" (status>1 / ready_pressed_at /
 *      excluded / deleted). The earlier _processItems bug dropped DB rows
 *      even when bot.deleteMessage returned false, leaving Telegram
 *      messages dangling.
 *
 * Telegram constraint: a non-admin bot can only delete its own messages for
 * the first 48 hours after sending. If the bot is not admin and the message
 * is older, this script will get HTTP 400 "message to delete not found" and
 * simply skip — same outcome as leaving it alone. To clean up older orphans
 * the bot needs admin rights with the delete_messages permission in the
 * group.
 *
 * Usage:
 *   cd /var/www/veranda_my_usr/data/www/veranda.my
 *   /opt/php82/bin/php scripts/kitchen/cleanup_orphan_alerts.php
 *
 * Idempotent — safe to re-run. Prints a summary at the end.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\HttpClient;
use App\Infrastructure\Logger;
use App\Infrastructure\TelegramBotClient;

Config::load(__DIR__ . '/../../.env');
Logger::init(Config::get('LOG_LEVEL', 'info'));
date_default_timezone_set(Config::get('POSTER_SPOT_TIMEZONE', 'Asia/Ho_Chi_Minh'));

$db    = Database::getInstance();
$http  = new HttpClient(timeoutSeconds: 10);
$bot   = new TelegramBotClient(
    token:  Config::require('TELEGRAM_BOT_TOKEN'),
    http:   $http,
    chatId: Config::require('TELEGRAM_CHAT_ID')
);

$ks    = $db->t('kitchen_stats');
$ai    = $db->t('tg_alert_items');
$today = date('Y-m-d');

$tried = $deleted = $notFound = $stillOrphan = 0;

// Helper: try delete, log outcome, return one of 'deleted'|'not_found'|'other'
$tryDelete = function (int $messageId, int $kid) use ($bot, &$tried, &$deleted, &$notFound, &$stillOrphan): string {
    $tried++;
    $ok = $bot->deleteMessage($messageId);
    if ($ok) {
        $deleted++;
        echo "  msg={$messageId} kid={$kid} → deleted\n";
        return 'deleted';
    }
    // The bot couldn't delete. Two common reasons:
    //  - >48h ago and bot is not group admin (Telegram says "not found")
    //  - really gone (someone already deleted it manually)
    // Either way it's not our concern any more. Surface the difference for diagnostics.
    $stillOrphan++;
    echo "  msg={$messageId} kid={$kid} → still orphan (likely >48h, make the bot admin)\n";
    return 'still_orphan';
};

echo "=== PASS 1 — past-day tg_alert_items rows ===\n";
$past = $db->query(
    "SELECT transaction_date, kitchen_stats_id, message_id
     FROM {$ai}
     WHERE transaction_date < ? AND message_id IS NOT NULL
     ORDER BY transaction_date, kitchen_stats_id",
    [$today]
)->fetchAll();
echo "found " . count($past) . " rows\n";
foreach ($past as $r) {
    $msgId = (int) $r['message_id'];
    $kid   = (int) $r['kitchen_stats_id'];
    $tryDelete($msgId, $kid);
    // Whether Telegram took it or not, drop the bookkeeping row so it doesn't
    // come around again on every run.
    $db->query(
        "DELETE FROM {$ai} WHERE transaction_date = ? AND kitchen_stats_id = ?",
        [$r['transaction_date'], $kid]
    );
}

echo "\n=== PASS 2 — kitchen_stats with tg_message_id but no live alert ===\n";
$dangling = $db->query(
    "SELECT ks.id, ks.tg_message_id
     FROM {$ks} ks
     LEFT JOIN {$ai} ai
       ON ai.kitchen_stats_id = ks.id AND ai.transaction_date = ks.transaction_date
     WHERE ks.tg_message_id IS NOT NULL
       AND ai.kitchen_stats_id IS NULL
       AND (
            ks.ready_pressed_at IS NOT NULL
         OR ks.status > 1
         OR COALESCE(ks.exclude_from_dashboard, 0) = 1
         OR COALESCE(ks.was_deleted, 0) = 1
       )
     ORDER BY ks.id"
)->fetchAll();
echo "found " . count($dangling) . " rows\n";
foreach ($dangling as $r) {
    $msgId = (int) $r['tg_message_id'];
    $kid   = (int) $r['id'];
    $tryDelete($msgId, $kid);
    // Null the back-pointer so future audits ignore this row.
    $db->query("UPDATE {$ks} SET tg_message_id = NULL WHERE id = ?", [$kid]);
}

echo "\n=== summary ===\n";
echo "tried:        {$tried}\n";
echo "deleted ok:   {$deleted}\n";
echo "still orphan: {$stillOrphan}  (need bot to be admin to delete these)\n";
