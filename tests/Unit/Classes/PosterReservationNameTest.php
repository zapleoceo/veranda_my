<?php
declare(strict_types=1);

namespace Tests\Unit\Classes;

use App\Classes\PosterReservationHelper;
use PHPUnit\Framework\TestCase;

final class PosterReservationNameTest extends TestCase
{
    public function testInitialsUseFullNameWithoutShortSurname(): void
    {
        self::assertSame(['first_name' => 'Айна М'], PosterReservationHelper::posterNameFields('Айна М'));
        self::assertSame(['first_name' => 'А Иванова'], PosterReservationHelper::posterNameFields('А Иванова'));
    }

    public function testSingleCharacterNamesArePaddedForPoster(): void
    {
        self::assertSame(['first_name' => 'М.'], PosterReservationHelper::posterNameFields('М'));
        self::assertSame(['first_name' => 'A.'], PosterReservationHelper::posterNameFields('A'));
    }

    public function testNormalNamesAndOptionalSurname(): void
    {
        self::assertSame(['first_name' => 'Айна', 'last_name' => 'Иванова'], PosterReservationHelper::posterNameFields('Айна Иванова'));
        self::assertSame(['first_name' => 'Айна'], PosterReservationHelper::posterNameFields('Айна'));
        self::assertSame(['first_name' => 'Li', 'last_name' => 'Wu'], PosterReservationHelper::posterNameFields('Li Wu'));
        self::assertSame(['first_name' => 'Айна М'], PosterReservationHelper::posterNameFields("  Айна\t М  "));
        self::assertSame(['first_name' => 'Guest'], PosterReservationHelper::posterNameFields('  '));
    }
}
