<?php

declare(strict_types=1);

namespace Tests\Unit\AiBot;

use App\AiBot\PayoutParser;
use PHPUnit\Framework\TestCase;

final class PayoutParserTest extends TestCase
{
    public const IGOR = 'Раздал половину дивидендов за сентябрь: Олег 5 378 800 (переводы 5.000.000 + 378.800), '
        . 'Дима 3 693 200, Ли 3 978 800, Игорь 4 057 200, Стас 2 436 000';

    public function test_igor_example(): void
    {
        $r = (new PayoutParser())->parse(self::IGOR);
        $this->assertSame([], $r['errors']);
        $this->assertSame('сентябрь', $r['period'], '«за сентябрь» — период, не дата транзакции');
        $this->assertSame(['Олег', 'Дима', 'Ли', 'Игорь', 'Стас'], array_column($r['people'], 'name'));
        $this->assertSame([5378800, 3693200, 3978800, 4057200, 2436000], array_column($r['people'], 'amount'));
        $this->assertSame([5000000, 378800], $r['people'][0]['parts']);
        $this->assertSame([], $r['people'][1]['parts']);
        $this->assertNull($r['people'][0]['error']);
    }

    public function test_separators_lines_and_parts_mismatch(): void
    {
        $r = (new PayoutParser())->parse("Олег: 1,000,000 (переводы 600 000 + 300 000)\nДима — 250.000\nЛи 75000");
        $this->assertSame([1000000, 250000, 75000], array_column($r['people'], 'amount'));
        $this->assertNotNull($r['people'][0]['error'], 'части не сходятся с суммой → ошибка');
        $this->assertNull($r['period']);
    }

    public function test_nothing_found(): void
    {
        $r = (new PayoutParser())->parse('привет, как дела');
        $this->assertSame([], $r['people']);
        $this->assertNotSame([], $r['errors']);
    }
}
