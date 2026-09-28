<?php

namespace App\Support;

use Carbon\CarbonInterface;

class ReportPeriod
{
    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    public static function range(string $period, mixed $from, mixed $to): ?array
    {
        return match ($period) {
            'month' => [now()->copy()->startOfMonth(), now()->copy()->endOfMonth()],
            'week' => [now()->copy()->startOfWeek(), now()->copy()->endOfWeek()],
            'day' => [now()->copy()->startOfDay(), now()->copy()->endOfDay()],
            'custom' => $from && $to
                ? [now()->parse($from)->startOfDay(), now()->parse($to)->endOfDay()]
                : null,
            default => null,
        };
    }
}
