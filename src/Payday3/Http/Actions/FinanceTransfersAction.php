<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Contracts\FinanceTransferServiceInterface;
use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/finance/transfers?dateFrom=&dateTo=
 *
 * Renders the Финансовые транзакции card client-side. Returns both
 * Vietnam and Tips block payloads plus the account IDs needed for the
 * "Создать транзакцию" mutation hook.
 */
final class FinanceTransfersAction
{
    public function __construct(
        private readonly FinanceTransferServiceInterface   $service,
        private readonly LocalSettingsRepositoryInterface $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
            $vietnam = $this->service->vietnam($range);
            $tips    = $this->service->tips($range);
            $cfg     = $this->settings->load();
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }
        return JsonResponder::ok($response, [
            'range'   => $range->asArray(),
            'vietnam' => $vietnam,
            'tips'    => $tips,
            'accounts' => [
                'andrey_id'  => $cfg->accountAndreyId,
                'tips_id'    => $cfg->accountTipsId,
                'vietnam_id' => $cfg->accountVietnamId,
            ],
        ]);
    }
}
