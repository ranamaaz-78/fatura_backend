<?php

namespace App\Support;

/** Small text tidy-ups so names and addresses are stored the same way however they were typed. */
final class Text
{
    /** Legal forms and abbreviations that stay in capitals ("S.L.", "LLC", "NIF"). */
    private const KEEP_UPPER = [
        'SL', 'SA', 'SLU', 'SLL', 'SAU', 'SRL', 'SAS', 'SC', 'CB', 'LLC', 'LLP', 'LTD', 'PVT', 'INC', 'PLC', 'GMBH',
        'NIF', 'NIE', 'CIF', 'IVA', 'YK', 'UK', 'USA', 'UAE', 'EU', 'PC', 'TV', 'IT',
    ];

    /** Small joining words that stay lower case unless they start the text. */
    private const KEEP_LOWER = [
        'de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'o', 'u', 'en', 'al', 'da', 'do', 'das', 'dos', 'di',
        'van', 'von', 'der', 'den', 'and', 'of', 'the', 'for', 'to',
    ];

    /**
     * "taller de MARTA s.l." becomes "Taller de Marta S.L.".
     *
     * Words that already mix upper and lower case (iPhone, McDonald, DevPremises) are left as typed.
     */
    public static function proper(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if ($value === '') {
            return '';
        }

        $words = explode(' ', $value);

        foreach ($words as $index => $word) {
            $words[$index] = self::word($word, $index === 0);
        }

        return implode(' ', $words);
    }

    private static function word(string $word, bool $first): string
    {
        // "jean-luc" and "o'brien": each part is a word of its own.
        if (preg_match('/[-\x{2019}\'\/]/u', $word) === 1) {
            $parts = preg_split('/([-\x{2019}\'\/])/u', $word, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$word];

            foreach ($parts as $i => $part) {
                if ($i % 2 === 0 && $part !== '') {
                    $parts[$i] = self::word($part, $first && $i === 0);
                }
            }

            return implode('', $parts);
        }

        $letters = preg_replace('/[^\p{L}]/u', '', $word) ?? '';

        if ($letters === '') {
            return $word;
        }

        // Initials such as "j.p." or "s.l.".
        if (preg_match('/^(\p{L}\.)+$/u', $word) === 1) {
            return mb_strtoupper($word);
        }

        if (in_array(mb_strtoupper($letters), self::KEEP_UPPER, true) && self::plain($word)) {
            return mb_strtoupper($word);
        }

        // Street numbers and the like: "5b" -> "5B", "2º" stays.
        if (preg_match('/\d/u', $word) === 1) {
            return mb_strtoupper($word);
        }

        if (! $first && in_array(mb_strtolower($word), self::KEEP_LOWER, true)) {
            return mb_strtolower($word);
        }

        $hasLower = $letters !== mb_strtoupper($letters);
        $hasUpper = $letters !== mb_strtolower($letters);

        // Mixed case on purpose (iPhone, McDonald): leave it.
        if ($hasLower && $hasUpper && ! self::onlyFirstUpper($letters)) {
            return $word;
        }

        return mb_convert_case($word, MB_CASE_TITLE);
    }

    /** Letters, with dots allowed: not a random token such as an email. */
    private static function plain(string $word): bool
    {
        return preg_match('/^[\p{L}.]+$/u', $word) === 1;
    }

    private static function onlyFirstUpper(string $letters): bool
    {
        return mb_substr($letters, 1) === mb_strtolower(mb_substr($letters, 1));
    }
}
