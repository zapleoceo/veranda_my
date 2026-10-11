<?php

declare(strict_types=1);

namespace App\AiBot;

interface OfficePollerInterface
{
    /** Один проход: отправить due-строки outbox, забрать обновления своих задач. @return array<string,int> счётчики */
    public function run(): array;
}
