<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Services\PurchaseRecorder;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** What the company bought from its suppliers, and the form that records a new delivery. */
class PurchaseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PurchaseRecorder $recorder) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['all', 'active', 'voided'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // The export asks for the lines of every purchase on the page, so a whole list can be written out.
            'with_lines' => ['nullable', Rule::in(['0', '1', 'true', 'false', 0, 1, true, false])],
        ]);

        $query = Purchase::query()
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('supplier_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('purchased_on', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('purchased_on', '<=', $date))
            ->when(($filters['status'] ?? 'all') === 'active', fn (Builder $q) => $q->whereNull('voided_at'))
            ->when(($filters['status'] ?? 'all') === 'voided', fn (Builder $q) => $q->whereNotNull('voided_at'))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.$search.'%';
                $q->where(function (Builder $inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhere('supplier_name', 'like', $like)
                        ->orWhere('supplier_invoice_no', 'like', $like)
                        ->orWhereHas('lines', fn (Builder $lines) => $lines->where('article', 'like', $like));
                });
            });

        $page = (clone $query)
            ->withCount('lines')
            ->when($request->boolean('with_lines'), fn (Builder $q) => $q->with('lines'))
            ->orderByDesc('purchased_on')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10);

        // This month, without the cancelled ones: how many deliveries, how much they cost, from how many suppliers.
        $month = Purchase::query()->whereNull('voided_at')->where('purchased_on', '>=', now()->startOfMonth()->toDateString());

        return $this->success([
            'items' => PurchaseResource::collection($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'stats' => [
                'month_count' => (clone $month)->count(),
                'month_cents' => (int) (clone $month)->sum('total_cents'),
                'month_suppliers' => (clone $month)->distinct()->count('supplier_id'),
            ],
        ]);
    }

    public function show(Purchase $purchase): JsonResponse
    {
        return $this->success(new PurchaseResource($purchase->load(['lines', 'creator:id,name'])));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'purchased_on' => ['required', 'date', 'before_or_equal:today'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999999'],
            'lines.*.unit_cost' => ['required', 'integer', 'min:0', 'max:999999999'],
        ]);

        $purchase = $this->recorder->record($request->user(), $data);

        return $this->success(new PurchaseResource($purchase), __('Purchase saved. The stock was updated.'), 201);
    }

    public function void(Request $request, Purchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $purchase = $this->recorder->void($request->user(), $purchase, $data['reason']);

        return $this->success(new PurchaseResource($purchase), __('Purchase cancelled. The stock was taken back.'));
    }
}
