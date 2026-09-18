<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\ReturnPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Open redirect через ?next=: браузеры превращают `/\evil.com` и
 * `/<TAB>/evil.com` в `//evil.com`, а старая проверка ловила только `//`.
 */
final class ReturnPathTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function unsafe(): array
    {
        return [
            'empty'                 => [''],
            'relative'              => ['admin'],
            'absolute url'          => ['https://evil.com/'],
            'protocol relative'     => ['//evil.com'],
            'backslash'             => ['/\\evil.com'],
            'backslash inside'      => ['/admin\\..\\x'],
            'tab'                   => ["/\t/evil.com"],
            'newline'               => ["/\n/evil.com"],
            'cr'                    => ["/admin\r"],
            'nul'                   => ["/admin\0"],
            'space'                 => ['/ /evil.com'],
            'nbsp'                  => ["/\u{00A0}/evil.com"],
            'del'                   => ["/\x7F/evil.com"],
            'invalid utf8'          => ["/\xC3\x28"],
            'login loop'            => ['/login?next=/x'],
            'logout'                => ['/logout'],
            'auth endpoint'         => ['/auth/callback'],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_rejects(string $path): void
    {
        $this->assertFalse(ReturnPath::isSafe($path));
    }

    /** @return array<string,array{0:string}> */
    public static function safe(): array
    {
        return [
            'admin'       => ['/admin'],
            'payday'      => ['/payday3/?dateFrom=2026-09-01&dateTo=2026-09-18'],
            'encoded'     => ['/reservations/?q=%20x'],
            'fragment'    => ['/employees#tab'],
            'bloggers'    => ['/bloggers'],
        ];
    }

    #[DataProvider('safe')]
    public function test_accepts(string $path): void
    {
        $this->assertTrue(ReturnPath::isSafe($path));
    }
}
