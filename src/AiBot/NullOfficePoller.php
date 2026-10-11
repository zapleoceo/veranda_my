<?php

declare(strict_types=1);

namespace App\AiBot;

/** Офис не настроен: ни БД, ни сети. */
final class NullOfficePoller implements OfficePollerInterface
{
    public function run(): array
    {
        return ['sent' => 0, 'polled' => 0, 'questions' => 0, 'results' => 0, 'delivered' => 0, 'errors' => 0];
    }
}
