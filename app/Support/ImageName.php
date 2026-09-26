<?php

namespace App\Support;

class ImageName
{
    public const MAX_LENGTH = 180;

    /** Extensions we drop from a label, because the name is stored without one. */
    private const STRIPPED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Turns anything the user typed, or any uploaded file name, into a bare label.
     * Returns null when nothing usable is left.
     */
    public static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Directory separators and control characters never belong in a label.
        $name = preg_replace('/[\/\\\\]+/', ' ', $value) ?? '';
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        $name = self::stripExtension($name);
        $name = trim($name, " .\t\n\r\0\x0B");

        if ($name === '' || $name === '.' || $name === '..') {
            return null;
        }

        return mb_substr($name, 0, self::MAX_LENGTH);
    }

    /** Case-insensitive uniqueness key. "Stone1" and "stone1" are the same file. */
    public static function key(string $name): string
    {
        return mb_strtolower($name);
    }

    private static function stripExtension(string $name): string
    {
        $dot = mb_strrpos($name, '.');

        if ($dot === false || $dot === 0) {
            return $name;
        }

        $extension = mb_strtolower(mb_substr($name, $dot + 1));

        return in_array($extension, self::STRIPPED_EXTENSIONS, true)
            ? mb_substr($name, 0, $dot)
            : $name;
    }
}
