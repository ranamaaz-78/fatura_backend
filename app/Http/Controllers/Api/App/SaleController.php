<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\SalesDocumentResource;
use App\Models\CompanyPaymentMethod;
use App\Models\SalesDocument;
use App\Models\SalesDocumentReturn;
use App\Models\SalesDocumentSettlement;
use App\Services\AlbaranInvoicer;
use App\Services\DocumentNumber;
use App\Services\SaleIssuer;
use App\Services\SaleReturner;
use App\Services\SaleSettler;
use App\Services\SaleVoider;
use App\Services\SettlementInvoicer;
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
        private readonly SaleVoider $voider,
        private readonly SaleSettler $settler,
        private readonly SaleReturner $returner,
        private readonly SettlementInvoicer $invoicer,
        private readonly AlbaranInvoicer $albaranInvoicer,
        private readonly DocumentNumber $numbers,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(SalesDocument::TYPES)],
            'payment_status' => ['nullable', Rule::in(SalesDocument::PAYMENTS)],
            'display_status' => ['nullable', Rule::in(['pending', 'paid', 'partial', 'voided'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $base = SalesDocument::query()
            ->when($filters['type'] ?? null, function (Builder $query, string $type) {
                $query->where('type', $type);
                if ($type === 'quotation') {
                    $query->whereNull('converted_at');
                }
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.$search.'%';
                $query->where(function (Builder $inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhere('client_name', 'like', $like)
                        ->orWhere('client_company', 'like', $like);
                });
            });

        $documents = (clone $base)
            ->with('paymentMethod')
            ->withSum('settlements as settled_cents', 'total_cents')
            ->when($filters['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when($filters['display_status'] ?? null, fn ($query, $status) => $this->applyDisplayStatus($query, $status))
            ->latest('issued_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 8)
            ->withQueryString();

        return $this->success([
            'items' => SalesDocumentResource::collection($documents->items()),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
            'counts' => $this->listCounts($base),
            'stats' => $this->listStats($base),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $type = $request->validate([
            'type' => ['required', Rule::in(SalesDocument::ISSUABLE)],
        ])['type'];

        $companyId = (int) $request->user()->company_id;

        return $this->success([
            'number' => $this->numbers->peek($companyId, $type),
            'client_code' => $this->numbers->peek($companyId, 'client'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->documentRules());

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
        $sale->load([...SaleSettler::relations(), 'fromSettlement.document']);

        return $this->success(new SalesDocumentResource($sale));
    }

    public function update(Request $request, SalesDocument $sale): JsonResponse
    {
        $data = $request->validate($this->documentRules(forUpdate: true));
        $data['issued_at'] = $request->date('issued_at');
        $data['save_customer'] = (bool) ($data['save_customer'] ?? false);

        $document = $this->issuer->updateQuote($request->user(), $sale, $data);

        return $this->success(
            new SalesDocumentResource($document),
            __(':number updated.', ['number' => $document->number]),
        );
    }

    public function convert(Request $request, SalesDocument $sale): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['factura', 'albaran'])],
            'payment_status' => ['required', Rule::in(SalesDocument::MARKABLE_PAYMENTS)],
            'payment_method_id' => ['nullable', 'integer'],
        ]);

        $data['issued_at'] = now();

        $document = $this->issuer->convert($request->user(), $sale, $data);

        return $this->success(
            new SalesDocumentResource($document),
            __(':number issued.', ['number' => $document->number]),
            201,
        );
    }

    public function updatePayment(Request $request, SalesDocument $sale): JsonResponse
    {
        if (! SalesDocument::settlesPayment($sale->type)) {
            return $this->error(__('This document is not paid from here.'), 422);
        }

        if ($sale->isVoided()) {
            return $this->error(__('This document is voided.'), 422);
        }

        $data = $request->validate([
            'payment_status' => ['required', Rule::in(SalesDocument::MARKABLE_PAYMENTS)],
            'payment_method_id' => ['nullable', 'integer'],
        ]);

        if ($sale->payment_status === 'paid' && $data['payment_status'] === 'pending') {
            return $this->error(__('A paid document cannot go back to pending. Void it instead.'), 422);
        }

        $methodId = null;

        if ($data['payment_status'] === 'paid') {
            $methodId = CompanyPaymentMethod::requireActive(
                (int) $request->user()->company_id,
                $data['payment_method_id'] ?? null,
            );
        }

        $sale->update([
            'payment_status' => $data['payment_status'],
            'payment_method_id' => $methodId,
        ]);

        return $this->success(
            new SalesDocumentResource($sale->load(['lines', 'paymentMethod'])),
            $data['payment_status'] === 'paid' ? __('Marked as paid.') : __('Marked as pending.'),
        );
    }

    public function settle(Request $request, SalesDocument $sale): JsonResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
        ]);

        $document = $this->settler->settle($request->user(), $sale, $data);

        return $this->success(
            new SalesDocumentResource($document),
            $document->payment_status === 'paid'
                ? __('Proforma settled.')
                : __('Partial payment recorded.'),
        );
    }

    /** The invoice for one payment on a proforma: its pieces and prices, with IVA added, and no stock moved. */
    public function invoiceSettlement(Request $request, SalesDocument $sale, SalesDocumentSettlement $settlement): JsonResponse
    {
        $data = $request->validate([
            'iva' => ['nullable', 'array'],
            'iva.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // A rate from Settings: recargo de equivalencia is added to the invoice.
            'recargo_rate_id' => ['nullable', 'integer'],
        ]);

        $invoice = $this->invoicer->invoice($request->user(), $sale, $settlement, $data['iva'] ?? [], $data['recargo_rate_id'] ?? null);

        return $this->success(
            new SalesDocumentResource($invoice),
            __(':number issued.', ['number' => $invoice->number]),
            201,
        );
    }

    /** A delivery note turned into an invoice: same customer, pieces and prices, with IVA (and recargo) added, and no stock moved. */
    public function invoiceAlbaran(Request $request, SalesDocument $sale): JsonResponse
    {
        $data = $request->validate([
            'iva' => ['nullable', 'array'],
            'iva.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // A rate from Settings: recargo de equivalencia is added to the invoice.
            'recargo_rate_id' => ['nullable', 'integer'],
        ]);

        $invoice = $this->albaranInvoicer->invoice($request->user(), $sale, $data['iva'] ?? [], $data['recargo_rate_id'] ?? null);

        return $this->success(
            new SalesDocumentResource($invoice),
            __(':number issued.', ['number' => $invoice->number]),
            201,
        );
    }

    /** Pieces of a proforma that came back: they go back into stock and stop counting as owed. */
    public function returnPieces(Request $request, SalesDocument $sale): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $document = $this->returner->record($request->user(), $sale, $data);

        return $this->success(
            new SalesDocumentResource($document),
            __('Return recorded. The pieces are back in stock.'),
            201,
        );
    }

    public function cancelReturn(SalesDocument $sale, SalesDocumentReturn $return): JsonResponse
    {
        $document = $this->returner->cancel($sale, $return);

        return $this->success(
            new SalesDocumentResource($document),
            __('Return cancelled. The pieces are out of stock again.'),
        );
    }

    public function updateSettlement(Request $request, SalesDocument $sale, SalesDocumentSettlement $settlement): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        $document = $this->settler->updateQuantities($sale, $settlement, $data);

        return $this->success(
            new SalesDocumentResource($document),
            __('Payment quantity updated.'),
        );
    }

    public function void(Request $request, SalesDocument $sale): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $document = $this->voider->void($request->user(), $sale, trim($data['reason']));

        return $this->success(
            new SalesDocumentResource($document),
            __('Document voided. Stock has been put back.'),
        );
    }

    private function applyDisplayStatus(Builder $query, string $status): void
    {
        if ($status === 'voided') {
            $query->whereNotNull('voided_at');

            return;
        }

        $query->whereNull('voided_at')->where('payment_status', $status);
    }

    /**
     * @return array{all: int, pending: int, paid: int, partial: int, voided: int}
     */
    private function listCounts(Builder $base): array
    {
        $live = fn () => (clone $base)->whereNull('voided_at');

        return [
            'all' => (clone $base)->count(),
            'pending' => $live()->where('payment_status', 'pending')->count(),
            'paid' => $live()->where('payment_status', 'paid')->count(),
            'partial' => $live()->where('payment_status', 'partial')->count(),
            'voided' => (clone $base)->whereNotNull('voided_at')->count(),
        ];
    }

    /**
     * @return array{
     *     total_cents: int,
     *     paid_count: int,
     *     paid_cents: int,
     *     pending_count: int,
     *     pending_cents: int,
     *     settled_cents: int,
     *     month_count: int,
     *     month_cents: int,
     *     client_count: int
     * }
     */
    private function listStats(Builder $base): array
    {
        $live = (clone $base)->whereNull('voided_at');
        $monthStart = now()->copy()->startOfMonth();
        $monthEnd = now()->copy()->endOfMonth();

        return [
            'total_cents' => (int) (clone $live)->sum('total_cents'),
            'paid_count' => (clone $live)->where('payment_status', 'paid')->count(),
            'paid_cents' => (int) (clone $live)->where('payment_status', 'paid')->sum('total_cents'),
            'pending_count' => (clone $live)->where('payment_status', 'pending')->count(),
            'pending_cents' => (int) (clone $live)->where('payment_status', 'pending')->sum('total_cents'),
            'settled_cents' => (int) SalesDocumentSettlement::query()
                ->whereIn('sales_document_id', (clone $live)->select('id'))
                ->sum('total_cents'),
            'month_count' => (clone $live)->whereBetween('issued_at', [$monthStart, $monthEnd])->count(),
            'month_cents' => (int) (clone $live)->whereBetween('issued_at', [$monthStart, $monthEnd])->sum('total_cents'),
            'client_count' => (int) (clone $base)->distinct()->count('client_name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRules(bool $forUpdate = false): array
    {
        $rules = [
            'issued_at' => ['required', 'date'],
            'customer_id' => ['nullable', 'integer'],
            'save_customer' => ['nullable', 'boolean'],
            'client_code' => ['nullable', 'string', 'max:32'],
            'client_name' => ['required', 'string', 'max:180'],
            'client_company' => ['nullable', 'string', 'max:180'],
            'client_phone' => ['nullable', 'string', 'max:40'],
            'client_nif' => ['nullable', 'string', 'max:32'],
            'client_nie' => ['nullable', 'string', 'max:32'],
            'client_address' => ['nullable', 'string', 'max:255'],
            // A discount on the whole bill: a percentage, or an amount in cents.
            'discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'discount_value' => ['nullable', 'required_with:discount_type', 'numeric', 'min:0'],
            // A rate from Settings. The server works out the amount. Invoices and quotations only.
            'recargo_rate_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.sr_number' => ['nullable', 'string', 'max:64'],
            'lines.*.article' => ['required', 'string', 'max:180'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0'],
            'lines.*.discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.iva_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];

        if ($forUpdate) {
            return $rules;
        }

        return array_merge($rules, [
            'type' => ['required', Rule::in(SalesDocument::ISSUABLE)],
            'payment_status' => ['required', Rule::in(SalesDocument::MARKABLE_PAYMENTS)],
            'payment_method_id' => ['nullable', 'integer'],
        ]);
    }
}
