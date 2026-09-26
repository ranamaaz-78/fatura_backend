<?php

namespace App\Support;

use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

class Phone
{
    /**
     * Normalize a number to E.164. Unparseable input is returned trimmed, never dropped.
     */
    public static function e164(?string $value, ?string $country = null): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new PhoneNumber($value, $country ?? config('fatura.phone.default_country')))->formatE164();
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Digits-only form used by wa.me links.
     */
    public static function waDigits(?string $value, ?string $country = null): ?string
    {
        $e164 = self::e164($value, $country);

        if ($e164 === null) {
            return null;
        }

        $digits = ltrim(preg_replace('/\D+/', '', $e164) ?? '', '0');

        return $digits === '' ? null : $digits;
    }
}
