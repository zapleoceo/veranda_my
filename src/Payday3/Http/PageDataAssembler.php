<?php

declare(strict_types=1);

namespace App\Payday3\Http;

use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\PosterRepositoryInterface;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Domain\DateRange;
use App\Payday3\Domain\ReconciliationLink;
use App\Payday3\Domain\RowState;

/**
 * Pulls together every piece of data the view template needs for a
 * single render. Keeps the controller as one thin function and the
 * template free of any service calls / SQL.
 *
 * Result is intentionally an associative array (extract()-friendly)
 * rather than a typed DTO — the view is the only consumer and the
 * shape is documented inline.
 */
final class PageDataAssembler
{
    public function __construct(
        private readonly SepayRepositoryInterface  $sepay,
        private readonly PosterRepositoryInterface $poster,
        private readonly LinkRepositoryInterface   $links,
    ) {}

    /**
     * @return array{
     *   range:        DateRange,
     *   sepayOpen:    list<\App\Payday3\Domain\SepayTransaction>,
     *   sepayHidden:  list<\App\Payday3\Domain\SepayTransaction>,
     *   poster:       list<\App\Payday3\Domain\PosterTransaction>,
     *   links:        list<ReconciliationLink>,
     *   linksJson:    list<array>,
     *   linkBySepay:  array<int,list<ReconciliationLink>>,
     *   linkByPoster: array<int,list<ReconciliationLink>>,
     *   rowStateBySepay:  array<int,string>,
     *   rowStateByPoster: array<int,string>,
     * }
     */
    public function assemble(DateRange $range): array
    {
        $links = $this->links->listInRange($range);

        $bySepay = [];
        $byPoster = [];
        foreach ($links as $l) {
            $bySepay[$l->sepayId][]            = $l;
            $byPoster[$l->posterTransactionId][] = $l;
        }

        $sepayOpen   = $this->sepay->listOpenInRange($range);
        $sepayHidden = $this->sepay->listHiddenInRange($range);
        $poster      = $this->poster->listClosedInRange($range);

        // Pre-compute the CSS row class so the template stays loop-only.
        $rowStateBySepay = [];
        foreach ($sepayOpen   as $s) $rowStateBySepay[$s->id] = RowState::classify($bySepay[$s->id] ?? []);
        foreach ($sepayHidden as $s) $rowStateBySepay[$s->id] = RowState::HIDDEN;

        $rowStateByPoster = [];
        foreach ($poster as $p) {
            $rowStateByPoster[$p->transactionId] = RowState::classify($byPoster[$p->transactionId] ?? []);
        }

        return [
            'range'            => $range,
            'sepayOpen'        => $sepayOpen,
            'sepayHidden'      => $sepayHidden,
            'poster'           => $poster,
            'links'            => $links,
            'linksJson'        => array_map(static fn($l) => $l->toJsonShape(), $links),
            'linkBySepay'      => $bySepay,
            'linkByPoster'     => $byPoster,
            'rowStateBySepay'  => $rowStateBySepay,
            'rowStateByPoster' => $rowStateByPoster,
        ];
    }
}
