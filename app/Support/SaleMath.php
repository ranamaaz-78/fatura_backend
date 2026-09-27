<?php

namespace App\Support;

class SaleMath
{
    /**
     * A line is priced net of IVA. Discount comes off that base, then IVA is
     * added, and each step is rounded to the cent once.
     *
     * @return array{base: int, tax: int, total: int}
     */
    public static function line(int $quantity, int $unitCents, float $discountPercent, float $ivaPercent): array
    {
        $base = (int) round($quantity * $unitCents * (1 - $discountPercent / 100), 0, PHP_ROUND_HALF_UP);
        $tax = (int) round($base * ($ivaPercent / 100), 0, PHP_ROUND_HALF_UP);

        return [
            'base' => $base,
            'tax' => $tax,
            'total' => $base + $tax,
        ];
    }

    /** Catalog selling prices already contain IVA, so the line starts from the net. */
    public static function netOfIva(int $sellingCents, float $ivaPercent): int
    {
        if ($ivaPercent <= 0) {
            return $sellingCents;
        }

        return (int) round($sellingCents / (1 + $ivaPercent / 100), 0, PHP_ROUND_HALF_UP);
    }
}
