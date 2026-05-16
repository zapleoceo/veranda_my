<?php

declare(strict_types=1);

namespace App\Actions;

use App\Infrastructure\Database;
use App\Infrastructure\TelegramBotClient;

/**
 * Immutable context passed to every webhook action.
 * Replaces the "variable injection via require" anti-pattern.
 */
readonly class ActionContext
{
    public function __construct(
        public Database          $db,
        public TelegramBotClient $bot,
        public int               $actionId,
        public string            $chatId,
        public int               $messageId,
        public string            $callbackQueryId,
        public string            $username,
        public string            $actorName,
    ) {}
}
