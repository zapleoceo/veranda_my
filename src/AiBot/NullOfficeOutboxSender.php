<?php

declare(strict_types=1);

namespace App\AiBot;

/** Заглушка: ничего не отправляет и сети не касается; строки остаются pending. */
final class NullOfficeOutboxSender implements OfficeOutboxSenderInterface
{
    public function send(array $outboxRow): bool
    {
        return false;
    }
}
