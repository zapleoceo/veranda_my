<?php

declare(strict_types=1);

namespace Tests\Unit\Payday3\Fakes;

use App\Payday3\Contracts\LocalSettingsRepositoryInterface;
use App\Payday3\Domain\LocalSettings;

final class FixedSettings implements LocalSettingsRepositoryInterface
{
    public function __construct(private LocalSettings $s) {}

    public static function defaults(): self { return new self(LocalSettings::defaults()); }

    public function load(): LocalSettings { return $this->s; }

    public function save(array $payload): array { return ['ok' => true]; }
}
