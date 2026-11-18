<?php

namespace Tests\Unit;

use App\Support\Email;
use App\Support\Kobo;
use App\Support\Phone;
use App\Support\Usfm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public static function phones(): array
    {
        return [
            ['08123456789', '+2348123456789'],
            ['+234 812 345 6789', '+2348123456789'],
            ['2348123456789', '+2348123456789'],
            ['0812-345-6789', '+2348123456789'],
        ];
    }

    #[DataProvider('phones')]
    public function test_phone_normalisation(string $input, string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public function test_masking(): void
    {
        $this->assertSame('+234 ••• ••• 6789', Phone::mask('+2348123456789'));
        $this->assertSame('pr•••@example.com', Email::mask('praise@example.com'));
        $this->assertSame('a•••@b.co', Email::mask('ab@b.co'));
        $this->assertSame('praise@example.com', Email::normalize('  Praise@Example.COM '));
    }

    public function test_usfm_validation(): void
    {
        $this->assertCount(66, Usfm::BOOKS);
        $this->assertTrue(Usfm::isValid('jos', 1));
        $this->assertTrue(Usfm::isValid('PSA', 150));
        $this->assertFalse(Usfm::isValid('PSA', 151));
        $this->assertFalse(Usfm::isValid('XYZ'));
        $this->assertSame('GEN', Usfm::codeAt(0));
        $this->assertSame('REV', Usfm::codeAt(65));
    }

    public function test_kobo_formatting(): void
    {
        $this->assertSame('₦19,900.00', Kobo::format(1990000));
    }
}
