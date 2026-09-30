<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Six-digit codes that prove an applicant owns the email before the application is saved.
 * Only a keyed hash of the code is kept, for a short time, with a cap on wrong guesses.
 */
class ApplicationOtp
{
    public const TTL_SECONDS = 600;

    public const RESEND_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public const LOCKED = 'locked';

    /** Seconds the applicant must still wait before another code may be sent, or 0. */
    public function waitSeconds(string $email): int
    {
        $sentAt = Cache::get($this->cooldownKey($email));

        return $sentAt === null ? 0 : max(0, self::RESEND_SECONDS - (time() - (int) $sentAt));
    }

    /** Makes a fresh code (replacing any earlier one) and returns it in plain text for the email. */
    public function issue(string $email): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->codeKey($email), ['hash' => $this->hash($email, $code), 'attempts' => 0], self::TTL_SECONDS);
        Cache::put($this->cooldownKey($email), time(), self::RESEND_SECONDS);

        return $code;
    }

    /** Drops a code that could not be delivered so it cannot be used or block a retry. */
    public function discard(string $email): void
    {
        Cache::forget($this->codeKey($email));
        Cache::forget($this->cooldownKey($email));
    }

    public function verify(string $email, string $code): string
    {
        $entry = Cache::get($this->codeKey($email));

        if (! is_array($entry)) {
            return self::EXPIRED;
        }

        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($this->codeKey($email));

            return self::LOCKED;
        }

        if (! hash_equals($entry['hash'], $this->hash($email, $code))) {
            $entry['attempts']++;
            Cache::put($this->codeKey($email), $entry, self::TTL_SECONDS);

            return $entry['attempts'] >= self::MAX_ATTEMPTS ? self::LOCKED : self::INVALID;
        }

        Cache::forget($this->codeKey($email));

        return self::OK;
    }

    private function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', $email.'|'.$code, (string) config('app.key'));
    }

    private function codeKey(string $email): string
    {
        return 'apply_otp:'.sha1($email);
    }

    private function cooldownKey(string $email): string
    {
        return 'apply_otp_cd:'.sha1($email);
    }
}
