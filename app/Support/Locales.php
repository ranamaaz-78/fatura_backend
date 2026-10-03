<?php

namespace App\Support;

use Illuminate\Http\Request;

/** The languages the product speaks, and how a request or a person's choice is turned into one of them. */
final class Locales
{
    public const DEFAULT = 'en';

    /** @var list<string> */
    public const SUPPORTED = ['en', 'es'];

    public static function supported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::SUPPORTED, true);
    }

    /** "es", "es-ES" and "ES" all become "es"; anything we do not speak becomes null. */
    public static function normalize(?string $locale): ?string
    {
        if ($locale === null || trim($locale) === '') {
            return null;
        }

        $short = strtolower(substr(trim($locale), 0, 2));

        return self::supported($short) ? $short : null;
    }

    /** The best match for the browser's Accept-Language header, or null when it names nothing we speak. */
    public static function fromHeader(Request $request): ?string
    {
        $header = (string) $request->header('Accept-Language', '');

        if ($header === '') {
            return null;
        }

        foreach ($request->getLanguages() as $language) {
            if ($found = self::normalize($language)) {
                return $found;
            }
        }

        return null;
    }
}
