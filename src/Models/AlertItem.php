<?php

declare(strict_types=1);

namespace App\Models;

readonly class AlertItem
{
    public function __construct(
        public int $kitchenStatsId,
        public int $transactionId,
        public int|null $messageId,
        public string $lastTextHash,
        public string $lastSeenAt,
    ) {}

    public static function fromRow(array $row): self
    {
        $msgId = isset($row['message_id']) && (int) $row['message_id'] > 0
            ? (int) $row['message_id']
            : null;

        return new self(
            kitchenStatsId: (int) ($row['kitchen_stats_id'] ?? 0),
            transactionId:  (int) ($row['transaction_id'] ?? 0),
            messageId:      $msgId,
            lastTextHash:   (string) ($row['last_text_hash'] ?? ''),
            lastSeenAt:     (string) ($row['last_seen_at'] ?? ''),
        );
    }
}
