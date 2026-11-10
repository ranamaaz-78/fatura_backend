<?php

namespace App\Services;

use App\Models\Company;
use App\Models\DocumentCounter;
use App\Models\SalesDocument;

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
        'supplier_bill' => 'SB',
    ];

    /** The only document whose numbers a company can choose for itself (Settings → Numbering). */
    public const SERIES_KINDS = ['factura'];

    /** The counter that never restarts, used while a company numbers its invoices by its own pattern. */
    private const MANUAL_KIND = 'factura_manual';

    /** The letters a kind of document starts with in the built-in numbering. */
    public function defaultPrefix(string $kind): string
    {
        return self::PREFIX[$kind];
    }

    /**
     * How this company writes its invoice numbers when it chose its own ("ALP-00234"): the text in front of the
     * counter and how many digits the counter is padded to. Null means the built-in numbering (F-202610/00001,
     * which starts again every month). Every other kind of document always uses the built-in numbering.
     *
     * @return array{prefix: string, width: int}|null
     */
    public function manual(int $companyId, string $kind = 'factura'): ?array
    {
        if ($kind !== 'factura') {
            return null;
        }

        $saved = Company::withoutGlobalScopes()->whereKey($companyId)->value('document_series');
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;
        $mine = is_array($saved) ? ($saved['factura'] ?? null) : null;

        if (! is_array($mine) || ($mine['mode'] ?? null) !== 'manual' || ! isset($mine['prefix'], $mine['width'])) {
            return null;
        }

        return ['prefix' => (string) $mine['prefix'], 'width' => max(1, (int) $mine['width'])];
    }

    /**
     * Reads a number as the owner types it: the last run of digits is the counter, what comes before it is the text
     * in front, and the digit count is the width ("ALP-00234" → "ALP-", 5 digits, 234).
     *
     * @return array{prefix: string, width: int, number: int}
     */
    public function parse(string $typed): array
    {
        $typed = trim($typed);

        if (strlen($typed) > 30 || ! preg_match('/^([A-Za-z0-9\-\/_.]*?)(\d+)$/', $typed, $found)) {
            throw new \InvalidArgumentException(__('Write the number like ALP-00234: letters or symbols, then digits at the end.'));
        }

        if (strlen($found[2]) > 8) {
            throw new \InvalidArgumentException(__('The number can have at most 8 digits.'));
        }

        $number = (int) $found[2];

        if ($number < 1) {
            throw new \InvalidArgumentException(__('The number must be 1 or more.'));
        }

        return ['prefix' => $found[1], 'width' => strlen($found[2]), 'number' => $number];
    }

    public function formatManual(string $prefix, int $width, int $number): string
    {
        return $prefix.str_pad((string) $number, $width, '0', STR_PAD_LEFT);
    }

    /** The last number this company issued under its own pattern (0 when none yet). */
    public function lastManual(int $companyId): int
    {
        return (int) (DocumentCounter::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('kind', self::MANUAL_KIND)
            ->where('year', 0)
            ->value('last_number') ?? 0);
    }

    /**
     * Starts numbering invoices with the owner's own pattern; the typed number is the very next invoice. Saved
     * inside the caller's transaction. With the same text in front the counter can only go up, so a number that was
     * issued is never handed out again.
     *
     * @return array{prefix: string, width: int, number: int}
     */
    public function setManual(Company $company, string $typed): array
    {
        $parsed = $this->parse($typed);
        $current = $this->manual((int) $company->id);
        $last = $this->lastManual((int) $company->id);

        if ($current !== null && $current['prefix'] === $parsed['prefix'] && $parsed['number'] <= $last) {
            throw new \InvalidArgumentException(__('The invoice number :number was already used. The next free one is :next.', [
                'number' => $this->formatManual($parsed['prefix'], $parsed['width'], $parsed['number']),
                'next' => $this->formatManual($parsed['prefix'], $parsed['width'], $last + 1),
            ]));
        }

        $saved = is_array($company->document_series) ? $company->document_series : [];
        $saved['factura'] = ['mode' => 'manual', 'prefix' => $parsed['prefix'], 'width' => $parsed['width']];
        $company->update(['document_series' => $saved]);

        DocumentCounter::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id, 'kind' => self::MANUAL_KIND, 'year' => 0],
            ['last_number' => $parsed['number'] - 1],
        );

        return $parsed;
    }

    /** Goes back to the built-in numbering. The monthly counters were never touched, so they carry on where they were. */
    public function setAutomatic(Company $company): void
    {
        $saved = is_array($company->document_series) ? $company->document_series : [];
        unset($saved['factura']);
        $company->update(['document_series' => $saved === [] ? null : $saved]);
    }

    /** The first free manual number from `$from` up: a number already on an invoice of this company is skipped. */
    private function freeManual(int $companyId, array $manual, int $from): int
    {
        $number = $from;

        while (SalesDocument::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('number', $this->formatManual($manual['prefix'], $manual['width'], $number))
            ->exists()) {
            $number++;
        }

        return $number;
    }

    /**
     * The number the next issue of this kind would receive. Nothing is reserved.
     *
     * $period is the month the document belongs to as YYYYMM (202610). In the built-in numbering each month has its
     * own series, so the serial starts again at 1 when the month changes. Without one it is the current month.
     */
    public function peek(int $companyId, string $kind, ?int $period = null): string
    {
        if (($manual = $this->manual($companyId, $kind)) !== null) {
            return $this->formatManual($manual['prefix'], $manual['width'], $this->freeManual($companyId, $manual, $this->lastManual($companyId) + 1));
        }

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
        $manual = $this->manual($companyId, $kind);
        $period = $manual !== null ? 0 : $this->periodFor($kind, $period);
        $counterKind = $manual !== null ? self::MANUAL_KIND : $kind;

        $counter = DocumentCounter::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('kind', $counterKind)
            ->where('year', $period)
            ->lockForUpdate()
            ->first();

        if ($counter === null) {
            $counter = DocumentCounter::create([
                'company_id' => $companyId,
                'kind' => $counterKind,
                // The "year" column holds the month (YYYYMM) for documents and 0 for client / supplier codes and the own-pattern counter.
                'year' => $period,
                'last_number' => 0,
            ]);
            // The row we just inserted is not locked, so lock it before counting.
            $counter = DocumentCounter::withoutGlobalScopes()->whereKey($counter->id)->lockForUpdate()->first();
        }

        if ($manual !== null) {
            $counter->last_number = $this->freeManual($companyId, $manual, $counter->last_number + 1);
            $counter->save();

            return $this->formatManual($manual['prefix'], $manual['width'], $counter->last_number);
        }

        $counter->last_number++;
        $counter->save();

        return $this->format($kind, $period, $counter->last_number);
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
