<?php

namespace App\Support;

class Barcode
{
    /**
     * Prefixes 200-299 are reserved for in-store use, so generated codes never
     * collide with a manufacturer's real EAN.
     */
    private const IN_STORE_PREFIX = '200';

    public static function randomEan13(): string
    {
        $body = self::IN_STORE_PREFIX.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);

        return $body.self::checkDigit($body);
    }

    public static function checkDigit(string $twelveDigits): string
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - $sum % 10) % 10);
    }
}
