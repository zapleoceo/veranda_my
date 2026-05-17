<?php

declare(strict_types=1);

namespace App\Payday3\Services;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Contracts\PosterApiProviderInterface;
use App\Payday3\Contracts\PosterCheckServiceInterface;
use App\Payday3\Contracts\TelegramNotifierInterface;
use App\Payday3\Domain\DateRange;

/**
 * Two operations on Poster checks:
 *
 *   find(transactionId, range)
 *     Paginated walk over transactions.getTransactions (per_page = 1000,
 *     hard cap 50 pages) looking for an exact transaction_id match.
 *
 *   remove(transactionId, byLabel)
 *     Calls transactions.removeTransaction with the service-user id
 *     from local settings; on success, fires a Telegram audit note
 *     to the configured chat/thread.
 *
 * No payday2 imports anywhere — service-user, chat-id, thread-id all
 * come from the injected LocalSettings repository.
 */
final class PosterCheckService implements PosterCheckServiceInterface
{
    public function __construct(
        private readonly PosterApiProviderInterface       $poster,
        private readonly TelegramNotifierInterface        $tg,
        private readonly LocalSettingsRepositoryInterface $settings,
    ) {}

    public function find(int $transactionId, DateRange $range): array
    {
        if ($transactionId <= 0) {
            throw new \InvalidArgumentException('Invalid transaction_id');
        }
        $api  = $this->poster->client();
        $page = 1;
        $per  = 1000;
        $maxPages = 50;
        while ($page <= $maxPages) {
            $resp = $api->request('transactions.getTransactions', [
                'date_from' => $range->from,
                'date_to'   => $range->to,
                'per_page'  => $per,
                'page'      => $page,
            ], 'GET');
            $rows = is_array($resp) ? ($resp['data'] ?? []) : [];
            if (!is_array($rows) || $rows === []) break;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                if ((int)($row['transaction_id'] ?? 0) === $transactionId) {
                    return [
                        'found'       => true,
                        'transaction' => $row,
                        'products'    => is_array($row['products'] ?? null) ? $row['products'] : [],
                    ];
                }
            }
            if (count($rows) < $per) break;
            $page++;
        }
        return ['found' => false];
    }

    public function remove(int $transactionId, string $byLabel): array
    {
        if ($transactionId <= 0) {
            throw new \InvalidArgumentException('Invalid transaction_id');
        }
        $settings = $this->settings->load();
        $resp = $this->poster->client()->request('transactions.removeTransaction', [
            'spot_tablet_id' => 1,
            'transaction_id' => $transactionId,
            'user_id'        => $settings->serviceUserId,
        ], 'POST');
        $errCode = is_array($resp) ? (int)($resp['err_code'] ?? 0) : 0;
        if ($errCode !== 0) {
            throw new \RuntimeException('Poster: err_code=' . $errCode);
        }

        $by   = trim($byLabel) !== '' ? trim($byLabel) : '—';
        $text = sprintf('Удален чек (%d) и кем - %s', $transactionId, $by);
        $tg   = $this->tg->sendText($text, $settings->telegramChatId, $settings->telegramThreadId);
        return [
            'ok'             => true,
            'telegram_ok'    => (bool)($tg['ok'] ?? false),
            'telegram_error' => $tg['error'] ?? '',
        ];
    }
}
