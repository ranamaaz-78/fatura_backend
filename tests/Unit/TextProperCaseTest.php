<?php

namespace Tests\Unit;

use App\Support\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextProperCaseTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function samples(): array
    {
        return [
            'lower case' => ['yk digital solutions', 'YK Digital Solutions'],
            'shouting' => ['TALLER RIVAS', 'Taller Rivas'],
            'legal form with dots' => ['taller rivas s.l.', 'Taller Rivas S.L.'],
            'legal form without dots' => ['luna fabrics SLU', 'Luna Fabrics SLU'],
            'joining words stay small' => ['taller DE marta y hijos', 'Taller de Marta y Hijos'],
            'first word is always capital' => ['de la torre', 'De la Torre'],
            'extra spaces' => ['  casa   mora ', 'Casa Mora'],
            'hyphen' => ['jean-luc picard', 'Jean-Luc Picard'],
            'apostrophe' => ["o'brien and sons", "O'Brien and Sons"],
            'brand with mixed case is kept' => ['iPhone Repair', 'iPhone Repair'],
            'camel case is kept' => ['DevPremises', 'DevPremises'],
            'street number' => ['calle mayor 5b', 'Calle Mayor 5B'],
            'ordinal sign' => ['avenida 12, 2º b', 'Avenida 12, 2º B'],
            'accents' => ['ÁLVARO PÉREZ', 'Álvaro Pérez'],
            'initials' => ['j.p. morgan', 'J.P. Morgan'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('samples')]
    public function test_it_writes_text_in_proper_case(string $typed, string $expected): void
    {
        $this->assertSame($expected, Text::proper($typed));
    }

    public function test_null_stays_null(): void
    {
        $this->assertNull(Text::proper(null));
    }
}
