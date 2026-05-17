<?php

declare(strict_types=1);

namespace App\Payday3\Contracts;

use App\Payday3\Domain\LocalSettings;

interface LocalSettingsRepositoryInterface
{
    /** Return the current settings (merged with defaults). Always succeeds. */
    public function load(): LocalSettings;

    /**
     * Validate the payload from the Settings modal, persist if valid.
     * @param array<string,mixed> $payload
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function save(array $payload): array;
}
