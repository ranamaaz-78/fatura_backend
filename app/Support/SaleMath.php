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

    /** IVA on an already-discounted base, rounded once to the cent. */
    public static function taxOn(int $baseCents, float $ivaPercent): int
    {
        return (int) round($baseCents * ($ivaPercent / 100), 0, PHP_ROUND_HALF_UP);
    }

    /** What a percentage takes off a bill, rounded once to the cent. */
    public static function percentOf(int $cents, float $percent): int
    {
        return (int) round($cents * ($percent / 100), 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Splits a discount over the lines in proportion to their bases, to the cent, so the
     * shares add up to exactly the discount. Leftover cents go to the largest remainders,
     * earlier lines first. The web form uses the same rule, so its totals match the server.
     *
     * @param  array<int, int>  $bases
     * @return array<int, int>
     */
    public static function allocate(array $bases, int $discountCents): array
    {
        $total = array_sum($bases);
        $shares = array_fill_keys(array_keys($bases), 0);

        if ($discountCents <= 0 || $total <= 0) {
            return $shares;
        }

        $remainders = [];
        $given = 0;

        foreach ($bases as $index => $base) {
            $exact = $discountCents * $base;
            $shares[$index] = intdiv($exact, $total);
            $remainders[$index] = $exact % $total;
            $given += $shares[$index];
        }

        $order = array_keys($bases);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);

        for ($i = 0, $left = $discountCents - $given; $i < $left; $i++) {
            $shares[$order[$i]]++;
        }

        return $shares;
    }

    /** Recargo de equivalencia is a percentage of the taxable base, rounded once to the cent. */
    public static function recargo(int $baseCents, float $percent): int
    {
        return (int) round($baseCents * ($percent / 100), 0, PHP_ROUND_HALF_UP);
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
