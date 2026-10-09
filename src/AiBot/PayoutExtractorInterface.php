<?php

declare(strict_types=1);

namespace App\AiBot;

/** Извлечение строк выплат из текста, когда детерминированный парсер не справился. */
interface PayoutExtractorInterface
{
    public function isAvailable(): bool;

    /** @return list<array{name:string,amount:int,parts:list<int>,error:?string}> */
    public function extract(string $sourceText): array;
}
