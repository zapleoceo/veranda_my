<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\MenuTranslationService;
use PHPUnit\Framework\TestCase;

final class MenuTranslationParseTest extends TestCase
{
    public function testMapsViToVnAndKeepsOnlyRequestedIds(): void
    {
        $ai = ['items' => [
            ['id' => 770, 'ru' => 'Сырники', 'en' => 'Cheese pancakes', 'vi' => 'Bánh phô mai', 'ko' => '시르니키'],
            ['id' => 999, 'ru' => 'Чужое', 'en' => 'Foreign', 'vi' => 'x', 'ko' => 'y'],
        ]];

        $out = MenuTranslationService::parse($ai, [770]);

        self::assertSame(['ru' => 'Сырники', 'en' => 'Cheese pancakes', 'vn' => 'Bánh phô mai', 'ko' => '시르니키'], $out[770]);
        self::assertArrayNotHasKey(999, $out);
    }

    public function testSkipsBlankLanguagesAndSquashesSpaces(): void
    {
        $ai = ['items' => [['id' => 5, 'ru' => "  Сырники   с  маком ", 'en' => '', 'vi' => '   ', 'ko' => '양귀비 시르니키']]];

        $out = MenuTranslationService::parse($ai, [5]);

        self::assertSame(['ru' => 'Сырники с маком', 'ko' => '양귀비 시르니키'], $out[5]);
    }

    public function testGarbageInputGivesNothing(): void
    {
        self::assertSame([], MenuTranslationService::parse(['items' => 'oops'], [1]));
        self::assertSame([], MenuTranslationService::parse([], [1]));
        self::assertSame([], MenuTranslationService::parse(['items' => [null, 'x', ['id' => 'abc']]], [1]));
    }
}
