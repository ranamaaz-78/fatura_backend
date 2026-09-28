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

    /** The number the next issue of this kind would receive. Nothing is reserved. */
    public function peek(int $companyId, string $kind, ?int $year = null): string
    {
        $year = $this->yearFor($kind, $year);
        $last = DocumentCounter::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('kind', $kind)
            ->where('year', $year)
            ->value('last_number') ?? 0;

        return $this->format($kind, $year, $last + 1);
    }

    /** Takes the next number inside the caller's transaction. */
    public function take(int $companyId, string $kind, ?int $year = null): string
    {
        $year = $this->yearFor($kind, $year);

        $counter = DocumentCounter::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('kind', $kind)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $counter = DocumentCounter::create([
                'company_id' => $companyId,
                'kind' => $kind,
                'year' => $year,
                'last_number' => 0,
            ]);
            // The row we just inserted is not locked, so lock it before counting.
            $counter = DocumentCounter::withoutGlobalScopes()->whereKey($counter->id)->lockForUpdate()->first();
        }

        $counter->last_number++;
        $counter->save();

        return $this->format($kind, $year, $counter->last_number);
    }

    public function format(string $kind, int $year, int $number): string
    {
        $prefix = self::PREFIX[$kind];

        if ($this->isStandingCode($kind)) {
            return sprintf('%s-%04d', $prefix, $number);
        }

        return sprintf('%s-%d/%04d', $prefix, $year, $number);
    }

    private function yearFor(string $kind, ?int $year): int
    {
        return $this->isStandingCode($kind) ? 0 : ($year ?? (int) now()->year);
    }

    /** Client and supplier codes keep counting; they do not reset each year. */
    private function isStandingCode(string $kind): bool
    {
        return $kind === 'client' || $kind === 'supplier';
    }
}
