<?php

namespace App\Services;

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
    ];

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

        return $this->format($kind, $period, $last + 1);
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

        $counter->last_number++;
        $counter->save();

        return $this->format($kind, $period, $counter->last_number);
    }

    public function format(string $kind, int $period, int $number): string
    {
        $prefix = self::PREFIX[$kind];

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
