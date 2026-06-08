<?php

declare(strict_types=1);

namespace App\Home\Content;

/**
 * Одно событие афиши (привязано к дню недели в WeeklyProgram).
 */
final class Event
{
    public function __construct(
        public readonly string $title,
        public readonly string $time,
        public readonly string $note,
    ) {
    }
}
