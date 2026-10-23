<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * The date a document is issued on. Documents are numbered per month (F-202610/00002), so a document can be dated
 * today's month at any time up to now, or an earlier month on that month's last day only. It can never be dated in
 * the future.
 *
 * The month is read from the date as the person wrote it (with the offset the browser sent), not from UTC, so a
 * document made just after midnight on the 1st stays in the new month.
 */
final class IssueDate
{
    /** A browser clock may run a little ahead of the server's. */
    private const CLOCK_SKEW_MINUTES = 5;

    /** The date as sent, keeping its own offset. */
    public static function parse(string $value): Carbon
    {
        return Carbon::parse($value);
    }

    /** The month it belongs to as YYYYMM, which picks the number series. */
    public static function period(Carbon $date): int
    {
        return (int) $date->format('Ym');
    }

    /** The date as the database stores it. */
    public static function stored(Carbon $date): Carbon
    {
        return $date->copy()->setTimezone(config('app.timezone'));
    }

    /**
     * Why this date cannot be used, or null when it can.
     *
     * Quotations are not checked at all (see SaleController::documentRules).
     */
    public static function problem(Carbon $date): ?string
    {
        $now = Carbon::now()->setTimezone($date->getTimezone());

        if ($date->gt($now->copy()->addMinutes(self::CLOCK_SKEW_MINUTES))) {
            return __('The date cannot be in the future.');
        }

        $sameMonth = $date->format('Ym') === $now->format('Ym');

        if (! $sameMonth && ! $date->isSameDay($date->copy()->endOfMonth())) {
            return __('For a past month the date can only be its last day, :date.', [
                'date' => $date->copy()->endOfMonth()->format('d/m/Y'),
            ]);
        }

        return null;
    }
}
