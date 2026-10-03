<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/** Dates and money written the way the current language writes them (1 de octubre de 2026, 1.234,56). */
final class Fmt
{
    public static function date(?CarbonInterface $date, ?string $locale = null): string
    {
        if ($date === null) {
            return '';
        }

        return $date->copy()->locale($locale ?? app()->getLocale())->isoFormat('LL');
    }

    public static function money(float|int $amount, ?string $locale = null): string
    {
        return (string) Number::format($amount, precision: 2, locale: $locale ?? app()->getLocale());
    }
}
