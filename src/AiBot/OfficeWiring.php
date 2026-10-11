<?php

declare(strict_types=1);

namespace App\AiBot;

use App\Infrastructure\Config;
use App\Infrastructure\TelegramBotClient;
use App\Payday3\Contracts\NamedLockInterface;
use Psr\Log\LoggerInterface;

/** Выбор реального/пустого отправителя по OFFICE_API_URL + OFFICE_SERVICE_TOKEN (оба непусты). */
final class OfficeWiring
{
    /** @param (callable(string):string)|null $get */
    public static function config(?callable $get = null): ?OfficeApiConfig
    {
        return OfficeApiConfig::fromConfig($get ?? static fn(string $k): string => Config::get($k));
    }

    public static function client(?OfficeApiConfig $cfg, ?OfficeHttpTransport $transport = null): ?OfficeApiClient
    {
        return $cfg === null ? null : new OfficeApiClient($cfg, $transport ?? new CurlOfficeTransport());
    }

    public static function sender(
        ?OfficeApiClient $client,
        OfficeTaskRepositoryInterface $repo,
        LoggerInterface $logger,
        ?TelegramBotClient $bot = null,
    ): OfficeOutboxSenderInterface {
        if ($client === null) {
            return new NullOfficeOutboxSender();
        }
        $notify = $bot === null ? null : static function (array $task, string $text) use ($bot): void {
            $thread = ($task['thread_id'] ?? null) !== null ? (int) $task['thread_id'] : null;
            $bot->withChatId((string) $task['chat_id'])->sendMessageWithKeyboard($text, [], $thread, (int) $task['origin_message_id']);
        };
        return new HttpOfficeOutboxSender($repo, $client, $logger, $notify);
    }

    public static function poller(
        ?OfficeApiClient $client,
        OfficeTaskRepositoryInterface $repo,
        OfficeOutboxSenderInterface $sender,
        OfficeTaskService $service,
        TelegramBotClient $bot,
        LoggerInterface $logger,
        ?NamedLockInterface $lock = null,
    ): OfficePollerInterface {
        return $client === null ? new NullOfficePoller() : new OfficePoller($repo, $sender, $client, $service, $bot, $logger, null, $lock);
    }
}
