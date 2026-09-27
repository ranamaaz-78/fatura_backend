<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesDocumentResource;
use App\Models\SalesDocument;
use App\Services\DocumentNumber;
use App\Services\SaleIssuer;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SaleIssuer $issuer,
        private readonly DocumentNumber $numbers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(SalesDocument::TYPES)],
            'payment_status' => ['nullable', Rule::in(SalesDocument::PAYMENTS)],
        ]);

        $documents = SalesDocument::query()
            ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.$search.'%';
                $query->where(function (Builder $inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhere('client_name', 'like', $like)
                        ->orWhere('client_company', 'like', $like)
                        ->orWhere('client_nif', 'like', $like);
                });
            })
            ->latest('issued_at')
            ->latest('id')
            ->limit(100)
            ->get();

        return $this->success(SalesDocumentResource::collection($documents));
    }

    public function preview(Request $request): JsonResponse
    {
        $type = $request->validate([
            'type' => ['required', Rule::in(SalesDocument::TYPES)],
        ])['type'];

        $companyId = (int) $request->user()->company_id;

        return $this->success([
            'number' => $this->numbers->peek($companyId, $type),
            'client_code' => $this->numbers->peek($companyId, 'client'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(SalesDocument::TYPES)],
            'issued_at' => ['required', 'date'],
            'payment_status' => ['required', Rule::in(SalesDocument::PAYMENTS)],
            'customer_id' => ['nullable', 'integer'],
            'save_customer' => ['nullable', 'boolean'],
            'client_code' => ['nullable', 'string', 'max:32'],
            'client_name' => ['required', 'string', 'max:180'],
            'client_company' => ['nullable', 'string', 'max:180'],
            'client_phone' => ['nullable', 'string', 'max:40'],
            'client_nif' => ['nullable', 'string', 'max:32'],
            'client_nie' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.sr_number' => ['nullable', 'string', 'max:64'],
            'lines.*.article' => ['required', 'string', 'max:180'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.iva_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $data['issued_at'] = $request->date('issued_at');
        $data['save_customer'] = (bool) ($data['save_customer'] ?? false);

        $document = $this->issuer->issue($request->user(), $data);

        return $this->success(
            new SalesDocumentResource($document),
            __(':number issued.', ['number' => $document->number]),
            201,
        );
    }

    public function show(SalesDocument $sale): JsonResponse
    {
        $sale->load('lines');

        return $this->success(new SalesDocumentResource($sale));
    }

    public function updatePayment(Request $request, SalesDocument $sale): JsonResponse
    {
        $status = $request->validate([
            'payment_status' => ['required', Rule::in(SalesDocument::PAYMENTS)],
        ])['payment_status'];

        $sale->update(['payment_status' => $status]);

        return $this->success(
            new SalesDocumentResource($sale->load('lines')),
            $status === 'paid' ? __('Marked as paid.') : __('Marked as pending.'),
        );
    }
}
