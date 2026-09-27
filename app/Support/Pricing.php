<?php

namespace App\Support;

class Pricing
{
    /**
     * Margin implied by a net selling price. IVA is not part of this price;
     * the invoice adds it later.
     */
    public static function marginFromPrices(int $buyingCents, int $sellingCents): float
    {
        if ($buyingCents <= 0) {
            return 0.0;
        }

        return round(($sellingCents - $buyingCents) / $buyingCents * 100, 2);
    }

    /**
     * Reads a money value written as "1.234,56", "1,234.56" or plain "12.5".
     */
    public static function toCents(int|float|string|null $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
        }

        $number = self::toNumber($value);

        return $number === null ? null : (int) round($number * 100, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Normalises a spreadsheet number: strips spaces and currency, and works
     * out whether a comma or a dot is the decimal separator.
     */
    public static function toNumber(int|float|string|null $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^\d,.\-]/', '', $value) ?? '';

        if ($clean === '' || $clean === '-') {
            return null;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Whichever separator comes last is the decimal one.
            $clean = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $clean))
                : str_replace(',', '', $clean);
        } elseif ($lastComma !== false) {
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }
}
