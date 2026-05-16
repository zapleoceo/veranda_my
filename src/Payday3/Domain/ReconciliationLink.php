<?php

declare(strict_types=1);

namespace App\Payday3\Domain;

/**
 * One edge in the reconciliation graph: sepay_id ↔ poster_transaction_id.
 *
 * link_type discriminates the matching strategy:
 *   - 'auto_green'  exact amount + same day, single candidate on each side
 *   - 'auto_yellow' amount matches but date wide / multiple candidates
 *   - 'auto_red'    heuristic only (e.g. reference code fuzzy match)
 *   - 'manual'      created by a human via the UI
 *
 * isManual is denormalised for fast colour lookup in the SVG renderer
 * (the JS computes stroke colour from {link_type, is_manual}).
 */
final class ReconciliationLink
{
    public function __construct(
        public readonly int    $sepayId,
        public readonly int    $posterTransactionId,
        public readonly string $linkType,
        public readonly bool   $isManual,
    ) {}

    public static function fromRow(array $r): self
    {
        return new self(
            sepayId:             (int)($r['sepay_id'] ?? 0),
            posterTransactionId: (int)($r['poster_transaction_id'] ?? 0),
            linkType:            (string)($r['link_type'] ?? 'auto_green'),
            isManual:            (int)($r['is_manual'] ?? 0) === 1,
        );
    }

    /** Shape expected by the JS line renderer. */
    public function toJsonShape(): array
    {
        return [
            'sepay_id'              => $this->sepayId,
            'poster_transaction_id' => $this->posterTransactionId,
            'link_type'             => $this->linkType,
            'is_manual'             => $this->isManual ? 1 : 0,
        ];
    }
}
