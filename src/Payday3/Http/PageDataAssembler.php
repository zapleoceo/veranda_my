<?php

declare(strict_types=1);

namespace App\Payday3\Http;

use App\Payday3\Contracts\LinkRepositoryInterface;
use App\Payday3\Contracts\PosterRepositoryInterface;
use App\Payday3\Contracts\SepayRepositoryInterface;
use App\Payday3\Domain\DateRange;

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
     *   range: DateRange,
     *   sepayOpen:   list<\App\Payday3\Domain\SepayTransaction>,
     *   sepayHidden: list<\App\Payday3\Domain\SepayTransaction>,
     *   poster:      list<\App\Payday3\Domain\PosterTransaction>,
     *   linksJson:   list<array>
     * }
     */
    public function assemble(DateRange $range): array
    {
        return [
            'range'       => $range,
            'sepayOpen'   => $this->sepay->listOpenInRange($range),
            'sepayHidden' => $this->sepay->listHiddenInRange($range),
            'poster'      => $this->poster->listClosedInRange($range),
            'linksJson'   => array_map(
                static fn($l) => $l->toJsonShape(),
                $this->links->listInRange($range),
            ),
        ];
    }
}
