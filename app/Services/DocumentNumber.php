<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DocumentCounter;

class DocumentNumber
{
    private const PREFIX = [
        'factura' => 'F',
        'albaran' => 'AL',
        'quotation' => 'Q',
        'proforma' => 'PF',
        'abono' => 'AB',
        'client' => 'C',
        'supplier' => 'S',
        'purchase' => 'PU',
    ];

    /** The documents whose series a company can set for itself (Settings → Numbering). */
    public const SERIES_KINDS = ['factura', 'albaran', 'quotation', 'proforma'];

    /** The letters a kind of document starts with when the company has not chosen its own. */
    public function defaultPrefix(string $kind): string
    {
        return self::PREFIX[$kind];
    }

    /**
     * How this company numbers this kind of document: the letters in front, the number every month starts from
     * ("start"), and, for one month only, a different number to begin that month at ("first", with the month it is
     * for as "first_period", YYYYMM). Only the four printable documents can be changed; everything else keeps the
     * standard.
     *
     * @return array{prefix: string, start: int, first: ?int, first_period: ?int}
     */
    public function series(int $companyId, string $kind): array
    {
        $standard = ['prefix' => self::PREFIX[$kind], 'start' => 1, 'first' => null, 'first_period' => null];

        if (! in_array($kind, self::SERIES_KINDS, true)) {
            return $standard;
        }

        $saved = Company::withoutGlobalScopes()->whereKey($companyId)->value('document_series');
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;
        $mine = is_array($saved) ? ($saved[$kind] ?? null) : null;

        if (! is_array($mine)) {
            return $standard;
        }

        $prefix = isset($mine['prefix']) && preg_match('/^[A-Za-z0-9]{1,10}$/', (string) $mine['prefix']) ? strtoupper((string) $mine['prefix']) : $standard['prefix'];
        $start = isset($mine['start']) ? max(1, (int) $mine['start']) : 1;
        $first = isset($mine['first'], $mine['first_period']) ? max(1, (int) $mine['first']) : null;

        return ['prefix' => $prefix, 'start' => $start, 'first' => $first, 'first_period' => $first === null ? null : (int) $mine['first_period']];
    }

    /** The number a month begins at: the "first" number in the month it was set for, the usual start in every other. */
    public function startFor(array $series, int $period): int
    {
        return $series['first'] !== null && $series['first_period'] === $period ? $series['first'] : $series['start'];
    }

    /**
     * The number the next issue of this kind would receive. Nothing is reserved.
     *
     * $period is the month the document belongs to as YYYYMM (202610). Each month has its own series, so the
     * serial starts again at 1 when the month changes. Without one it is the current month.
     */
    public function peek(int $companyId, string $kind, ?int $period = null): string
    {
        $period = $this->periodFor($kind, $period);
        $last = DocumentCounter::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('kind', $kind)
            ->where('year', $period)
            ->value('last_number') ?? 0;

        $series = $this->series($companyId, $kind);

        // The series never goes back: it continues after the last number used, or begins at the number the company chose for that month.
        return $this->format($kind, $period, max($last + 1, $this->startFor($series, $period)), $series['prefix']);
    }

    /** Takes the next number inside the caller's transaction. */
    public function take(int $companyId, string $kind, ?int $period = null): string
    {
        $period = $this->periodFor($kind, $period);

        $counter = DocumentCounter::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('kind', $kind)
            ->where('year', $period)
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $counter = DocumentCounter::create([
                'company_id' => $companyId,
                'kind' => $kind,
                // The "year" column holds the month (YYYYMM) for documents and 0 for client / supplier codes.
                'year' => $period,
                'last_number' => 0,
            ]);
            // The row we just inserted is not locked, so lock it before counting.
            $counter = DocumentCounter::withoutGlobalScopes()->whereKey($counter->id)->lockForUpdate()->first();
        }

        $series = $this->series($companyId, $kind);
        $counter->last_number = max($counter->last_number + 1, $this->startFor($series, $period));
        $counter->save();

        return $this->format($kind, $period, $counter->last_number, $series['prefix']);
    }

    public function format(string $kind, int $period, int $number, ?string $prefix = null): string
    {
        $prefix ??= self::PREFIX[$kind];

        if ($this->isStandingCode($kind)) {
            return sprintf('%s-%04d', $prefix, $number);
        }

        // F-202610/00002: an invoice of October 2026, the second one of that month.
        return sprintf('%s-%06d/%05d', $prefix, $period, $number);
    }

    private function periodFor(string $kind, ?int $period): int
    {
        return $this->isStandingCode($kind) ? 0 : ($period ?? (int) now()->format('Ym'));
    }

    /** Client and supplier codes keep counting; they do not restart each month. */
    private function isStandingCode(string $kind): bool
    {
        return $kind === 'client' || $kind === 'supplier';
    }
}
