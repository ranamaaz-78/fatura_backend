<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\SalesDocument;
use App\Support\ReportPeriod;
use App\Traits\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'period' => ['nullable', Rule::in(['all', 'month', 'week', 'day', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['all', 'received', 'pending', 'partial'])],
            'payment_method_id' => ['nullable', 'string', 'max:20'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $search = $filters['search'] ?? null;
        $period = $filters['period'] ?? 'all';
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 15;
        $range = ReportPeriod::range($period, $filters['from'] ?? null, $filters['to'] ?? null);

        $all = $this->entries();
        $inRange = $all->filter(fn (array $row) => $this->inRange($row, $range))->values();
        $matched = $inRange
            ->filter(fn (array $row) => $this->matchesStatus($row, $filters['status'] ?? 'all'))
            ->filter(fn (array $row) => $this->matchesMethod($row, $filters['payment_method_id'] ?? null))
            ->values();
        $entries = $matched
            ->filter(fn (array $row) => $this->matchesSearch($row, $search))
            ->sortByDesc(fn (array $row) => ($row['paid_at'] ?? '').'-'.$row['id'])
            ->values();

        $total = $entries->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        return $this->success([
            'items' => $entries->forPage($page, $perPage)->values(),
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'counts' => [
                'all' => $all->count(),
                'month' => $all->filter(fn (array $row) => $this->inRange($row, ReportPeriod::range('month', null, null)))->count(),
                'week' => $all->filter(fn (array $row) => $this->inRange($row, ReportPeriod::range('week', null, null)))->count(),
                'day' => $all->filter(fn (array $row) => $this->inRange($row, ReportPeriod::range('day', null, null)))->count(),
            ],
            'stats' => $this->stats($matched),
        ]);
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     */
    private function inRange(array $row, ?array $range): bool
    {
        if ($range === null) {
            return true;
        }

        $at = $row['paid_at'] ?? null;
        if (! is_string($at) || $at === '') {
            return false;
        }

        return now()->parse($at)->betweenIncluded($range[0], $range[1]);
    }

    private function matchesSearch(array $row, ?string $search): bool
    {
        if ($search === null || trim($search) === '') {
            return true;
        }

        $needle = mb_strtolower(trim($search));
        $haystack = mb_strtolower(implode(' ', array_filter([
            $row['document_number'] ?? '',
            $row['client_name'] ?? '',
            $row['client_company'] ?? '',
        ])));

        return str_contains($haystack, $needle);
    }

    private function matchesStatus(array $row, ?string $status): bool
    {
        if ($status === null || $status === '' || $status === 'all') {
            return true;
        }

        return ($row['status'] ?? null) === $status;
    }

    private function matchesMethod(array $row, mixed $methodId): bool
    {
        if ($methodId === null || $methodId === '' || $methodId === 'all') {
            return true;
        }

        if ($methodId === 'none') {
            return ($row['payment_method_id'] ?? null) === null;
        }

        return (int) ($row['payment_method_id'] ?? 0) === (int) $methodId;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function entries(): Collection
    {
        return SalesDocument::query()
            ->whereIn('type', ['factura', 'albaran', 'proforma'])
            ->whereNull('from_settlement_id')
            ->whereNull('from_document_id')
            ->whereNull('voided_at')
            // A proforma that came back whole was never a sale, so it has no place among the payments.
            ->whereRaw('(returned_cents = 0 OR returned_cents < total_cents)')
            ->with(['paymentMethod', 'settlements.paymentMethod'])
            ->withSum('settlements as settled_cents', 'total_cents')
            ->get()
            ->map(fn (SalesDocument $document) => $this->fromDocument($document));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{
     *     received_cents: int,
     *     received_count: int,
     *     pending_cents: int,
     *     pending_count: int,
     *     outstanding_cents: int,
     *     outstanding_count: int,
     *     client_count: int
     * }
     */
    private function stats(Collection $rows): array
    {
        $open = $rows->whereIn('status', ['pending', 'partial']);
        $receivedCount = $rows
            ->filter(fn (array $row) => (int) $row['amount_cents'] > (int) $row['outstanding_cents'])
            ->count();

        return [
            'received_cents' => (int) $rows->sum(fn (array $row) => (int) $row['amount_cents'] - (int) $row['outstanding_cents']),
            'received_count' => $receivedCount,
            'pending_cents' => (int) $open->sum('outstanding_cents'),
            'pending_count' => $open->count(),
            'outstanding_cents' => (int) $open->sum('outstanding_cents'),
            'outstanding_count' => $open->count(),
            'client_count' => $rows->pluck('client_name')->filter()->unique()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromDocument(SalesDocument $document): array
    {
        $settled = (int) $document->settledCents();
        // Pieces that came back are neither owed nor paid.
        $total = max(0, (int) $document->total_cents - (int) $document->returned_cents);
        $status = match ($document->payment_status) {
            'paid' => 'received',
            'partial' => 'partial',
            default => 'pending',
        };
        $method = $document->paymentMethod ?? $document->settlements->last()?->paymentMethod;
        $outstanding = $status === 'received' ? 0 : max(0, $total - $settled);

        return [
            'id' => 'document-'.$document->id,
            'kind' => 'document',
            'status' => $status,
            'document_id' => $document->id,
            'document_type' => $document->type,
            'document_number' => $document->number,
            'customer_id' => $document->customer_id,
            'client_code' => $document->client_code,
            'client_name' => $document->client_name,
            'client_company' => $document->client_company,
            'payment_method_id' => $method?->id,
            'payment_method' => $this->methodPayload($method),
            'amount_cents' => $total,
            'outstanding_cents' => $outstanding,
            'paid_at' => $document->issued_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function methodPayload(mixed $method): ?array
    {
        if ($method === null) {
            return null;
        }

        return [
            'id' => $method->id,
            'name' => $method->name,
        ];
    }
}
