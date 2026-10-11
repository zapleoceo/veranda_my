<?php

declare(strict_types=1);

namespace App\AiBot;

final class OfficeApiResponse
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
        public readonly ?int $retryAfter = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 200 || $this->status === 201;
    }

    /** Код ошибки Vera (`error`), без свободного текста. */
    public function errorCode(): string
    {
        $e = $this->data['error'] ?? '';
        return is_string($e) ? substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $e) ?? '', 0, 64) : '';
    }
}
