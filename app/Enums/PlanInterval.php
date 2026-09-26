<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum PlanInterval: string
{
    case Month = 'month';
    case Year = 'year';

    /**
     * Advance a date by one billing period without rolling into the next month.
     */
    public function advance(CarbonInterface $from, int $periods = 1): CarbonInterface
    {
        return match ($this) {
            self::Month => $from->copy()->addMonthsNoOverflow($periods),
            self::Year => $from->copy()->addYearsNoOverflow($periods),
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
