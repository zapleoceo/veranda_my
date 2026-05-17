<?php

declare(strict_types=1);

namespace App\Payday3\Http\Actions;

use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\PosterTransaction;
use App\Payday3\Domain\SepayTransaction;
use App\Payday3\Http\JsonResponder;
use App\Payday3\Http\PageDataAssembler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /payday3/api/data?dateFrom=&dateTo=
 *
 * Full IN-mode snapshot for the client. Lets the page re-render the
 * sepay+poster tables, totals, and link layer after sepay-sync /
 * poster-sync / day-clear without a hard `window.location.reload()`.
 *
 * The assembler is reused as-is (it owns the row-state computation),
 * and DTOs are flattened into JSON-friendly shapes here so the JS
 * never has to handle Money or Domain VOs.
 */
final class InDataAction
{
    public function __construct(private readonly PageDataAssembler $assembler) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $range = DateRange::fromQuery($request->getQueryParams());
            $data  = $this->assembler->assemble($range);
        } catch (\InvalidArgumentException $e) {
            return JsonResponder::error($response, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            return JsonResponder::error($response, $e->getMessage(), 500);
        }

        $sepay = array_map(
            static fn(SepayTransaction $s) => self::sepayShape($s, false),
            $data['sepayOpen'],
        );
        $hidden = array_map(
            static fn(SepayTransaction $s) => self::sepayShape($s, true),
            $data['sepayHidden'],
        );
        $poster = array_map(
            static fn(PosterTransaction $p) => self::posterShape($p),
            $data['poster'],
        );

        return JsonResponder::ok($response, [
            'range'       => $range->asArray(),
            'sepay'       => $sepay,
            'sepayHidden' => $hidden,
            'poster'      => $poster,
            'links'       => $data['linksJson'],
            'rowState'    => [
                'sepay'  => $data['rowStateBySepay']  ?? [],
                'poster' => $data['rowStateByPoster'] ?? [],
            ],
        ]);
    }

    private static function sepayShape(SepayTransaction $s, bool $hidden): array
    {
        return [
            'id'              => $s->id,
            'transaction_date'=> $s->transactionDate,
            'time'            => $s->transactionDate !== '' ? substr($s->transactionDate, 11, 8) : '',
            'amount'          => $s->amount->amount,
            'amount_fmt'      => $s->amount->format(),
            'payment_method'  => $s->paymentMethod,
            'content'         => $s->content,
            'reference_code'  => $s->referenceCode,
            'is_hidden'       => $hidden,
        ];
    }

    private static function posterShape(PosterTransaction $p): array
    {
        $total = $p->totalPayed();
        $time  = $p->dateClose !== '' ? substr($p->dateClose, 11, 8) : '';
        $pmFull = (string)($p->paymentMethodDisplay ?? '—');
        $pmLite = $pmFull;
        if (stripos($pmFull, 'vietnam') !== false)      $pmLite = 'VC';
        elseif (stripos($pmFull, 'bybit') !== false)    $pmLite = 'BB';
        return [
            'transaction_id'    => $p->transactionId,
            'receipt_number'    => $p->receiptNumber,
            'date_close'        => $p->dateClose,
            'time'              => $time,
            'payed_card'        => $p->payedCard->amount,
            'payed_card_fmt'    => $p->payedCard->format(),
            'tip_sum'           => $p->tipSum->amount,
            'tip_sum_fmt'       => $p->tipSum->format(),
            'total'             => $total->amount,
            'total_fmt'         => $total->format(),
            'payment_method'    => $pmFull,
            'payment_method_lite' => $pmLite,
            'waiter_name'       => $p->waiterName,
            'table_id'          => $p->tableId,
            'spot_id'           => $p->spotId,
        ];
    }
}
