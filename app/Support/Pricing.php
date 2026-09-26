<?php

namespace App\Support;

class Pricing
{
    /**
     * Selling price a product should carry: cost plus margin, then tax on top.
     *
     * Both percentages are applied in one expression and rounded once, so the
     * result never drifts the way a chain of rounded steps would.
     */
    public static function sellingPrice(int $buyingCents, float $marginPercent, float $ivaPercent): int
    {
        $value = $buyingCents * (1 + $marginPercent / 100) * (1 + $ivaPercent / 100);

        return (int) round($value, 0, PHP_ROUND_HALF_UP);
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
