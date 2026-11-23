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
use App\Services\DraftIssuer;
use App\Services\SaleIssuer;
use App\Services\SaleReturner;
use App\Services\SaleSettler;
use App\Services\SaleDeleter;
use App\Services\SaleVoider;
use App\Services\SettlementInvoicer;
use App\Support\IssueDate;
use App\Support\Permissions;
use App\Support\Pricing;
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
        private readonly SaleDeleter $deleter,
        private readonly SaleSettler $settler,
        private readonly SaleReturner $returner,
        private readonly SettlementInvoicer $invoicer,
        private readonly AlbaranInvoicer $albaranInvoicer,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * The four document kinds share these routes, so what a member may do depends on the document: a cashier can
     * issue invoices but not void them, an accountant can read them but not make them. Voiding is "void",
     * converting is "create" on what it becomes, recording or changing a payment (and returns) is "pay".
     */
    private function allow(Request $request, string $type, string $action): void
    {
        $area = Permissions::forDocument($type);

        // Something this kind of document cannot do at all (a quotation is never "paid", an invoice is never "edited"):
        // there is nothing to tick for it, so seeing the document is enough to reach the answer that it is not possible.
        if (! in_array($action, Permissions::modules()[$area] ?? [], true)) {
            $action = 'view';
        }

        $permission = $area.'.'.$action;

        if (! $request->user()->hasPermission($permission)) {
            abort(Permissions::denied());
        }
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(SalesDocument::TYPES)],
            'payment_status' => ['nullable', Rule::in(SalesDocument::PAYMENTS)],
            'display_status' => ['nullable', Rule::in(['pending', 'paid', 'partial', 'voided', 'draft'])],
            // The Deleted list: only for the types the person may delete.
            'trashed' => ['nullable', Rule::in(['0', '1', 'true', 'false', 0, 1, true, false])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:1000'],
        ]);

        $viewable = collect(SalesDocument::TYPES)
            ->filter(fn (string $type) => $request->user()->hasPermission(Permissions::forDocument($type).'.view'))
            ->values()
            ->all();

        if (isset($filters['type']) && ! in_array($filters['type'], $viewable, true)) {
            abort(Permissions::denied());
        }

        $deletable = collect($viewable)
            ->filter(fn (string $type) => $request->user()->hasPermission(Permissions::forDocument($type).'.delete'))
            ->values()
            ->all();
        $trashed = $request->boolean('trashed');

        $scope = function (Builder $query) use ($filters) {
            $query->when($filters['type'] ?? null, function (Builder $query, string $type) {
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

            return $query;
        };

        // The live list (what every count and total is about), and the Deleted list beside it.
        $base = $scope(SalesDocument::query()->whereIn('type', $viewable));
        $trash = $scope(SalesDocument::onlyTrashed()->whereIn('type', $deletable));
        $listed = $trashed ? $trash : $base;

        $documents = (clone $listed)
            ->with(['paymentMethod', 'deleter', 'settlements', 'convertedTo'])
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
            'counts' => $this->listCounts($base) + ['trashed' => (clone $trash)->count()],
            'stats' => $this->listStats($base),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['required', Rule::in(SalesDocument::ISSUABLE)],
            // The number follows the month the document is dated in.
            'issued_at' => ['nullable', 'date'],
        ]);
        $type = $filters['type'];

        $this->allow($request, $type, 'create');

        $companyId = (int) $request->user()->company_id;
        $period = isset($filters['issued_at']) ? IssueDate::period(IssueDate::parse($filters['issued_at'])) : null;

        return $this->success([
            'number' => $this->numbers->peek($companyId, $type, $period),
            'client_code' => $this->numbers->peek($companyId, 'client'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->documentRules());
        $this->allow($request, $data['type'], 'create');

        // Only an invoice is drafted: it holds its number now and takes its stock when issued.
        $data['draft'] = (bool) ($data['draft'] ?? false);
        if ($data['draft'] && $data['type'] !== 'factura') {
            return $this->error(__('Only an invoice can be saved as a draft.'), 422, ['draft' => [__('Only an invoice can be saved as a draft.')]]);
        }

        $issued = IssueDate::parse($data['issued_at']);
        $data['number_period'] = IssueDate::period($issued);
        $data['issued_at'] = IssueDate::stored($issued);
        $data['save_customer'] = (bool) ($data['save_customer'] ?? false);

        $document = $this->issuer->issue($request->user(), $data);

        return $this->success(
            new SalesDocumentResource($document),
            $document->isDraft()
                ? __(':number saved as a draft. The number is kept; the stock is taken when it is issued.', ['number' => $document->number])
                : __(':number issued.', ['number' => $document->number]),
            201,
        );
    }

    /** Issues a draft invoice: same number and date, the stock goes out now, and it can be marked paid. */
    public function issueDraft(Request $request, SalesDocument $sale, DraftIssuer $drafts): JsonResponse
    {
        $this->allow($request, $sale->type, 'create');

        $data = $request->validate([
            'payment_status' => ['required', Rule::in(SalesDocument::MARKABLE_PAYMENTS)],
            'payment_method_id' => ['nullable', 'integer'],
        ]);

        $document = $drafts->issue($sale, $data);

        return $this->success(new SalesDocumentResource($document), __(':number issued.', ['number' => $document->number]));
    }

    public function show(Request $request, SalesDocument $sale): JsonResponse
    {
        $this->allow($request, $sale->type, 'view');

        // A deleted document is seen only by people who may delete (and so restore) that kind.
        if ($sale->trashed()) {
            $this->allow($request, $sale->type, 'delete');
        }

        $sale->load([...SaleSettler::relations(), 'fromSettlement.document', 'deleter']);

        return $this->success(new SalesDocumentResource($sale));
    }

    public function update(Request $request, SalesDocument $sale): JsonResponse
    {
        // A draft invoice is still being made: changing it needs "create" (invoices have no "update" to tick, and
        // falling back to "view" would let a read-only member rewrite it).
        $this->allow($request, $sale->type, $sale->isDraft() ? 'create' : 'update');

        $data = $request->validate($this->documentRules(forUpdate: true));
        $data['issued_at'] = IssueDate::stored(IssueDate::parse($data['issued_at']));
        $data['save_customer'] = (bool) ($data['save_customer'] ?? false);

        $document = $this->issuer->updateDocument($request->user(), $sale, $data);

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

        $this->allow($request, $data['type'], 'create');

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
        $this->allow($request, $sale->type, 'pay');

        if (! SalesDocument::settlesPayment($sale->type)) {
            return $this->error(__('This document is not paid from here.'), 422);
        }

        if ($sale->isVoided()) {
            return $this->error(__('This document is voided.'), 422);
        }

        if ($sale->isDraft()) {
            return $this->error(__('Issue the draft first; a draft is not paid.'), 422);
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
        $this->allow($request, $sale->type, 'pay');

        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:'.Pricing::MAX_UNIT_CENTS],
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
        $this->allow($request, 'factura', 'create');
        $this->allow($request, $sale->type, 'view');

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
        $this->allow($request, 'factura', 'create');
        $this->allow($request, $sale->type, 'view');

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
        $this->allow($request, $sale->type, 'pay');

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

    public function cancelReturn(Request $request, SalesDocument $sale, SalesDocumentReturn $return): JsonResponse
    {
        $this->allow($request, $sale->type, 'pay');

        $document = $this->returner->cancel($sale, $return);

        return $this->success(
            new SalesDocumentResource($document),
            __('Return cancelled. The pieces are out of stock again.'),
        );
    }

    public function updateSettlement(Request $request, SalesDocument $sale, SalesDocumentSettlement $settlement): JsonResponse
    {
        $this->allow($request, $sale->type, 'pay');

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
        $this->allow($request, $sale->type, 'void');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $document = $this->voider->void($request->user(), $sale, trim($data['reason']));

        return $this->success(
            new SalesDocumentResource($document),
            __('Document voided. Stock has been put back.'),
        );
    }

    /** Deletes a document that was never paid. It can be brought back from the Deleted list. */
    public function destroy(Request $request, SalesDocument $sale): JsonResponse
    {
        $this->allow($request, $sale->type, 'delete');

        $document = $this->deleter->delete($request->user(), $sale);

        // A draft is not put in the bin: it stays as a voided invoice, so its number leaves no gap.
        if ($document->isDraft()) {
            return $this->success(new SalesDocumentResource($document), __(':number was a draft. It is kept as a voided invoice so the numbering has no gap.', ['number' => $document->number]));
        }

        return $this->success(new SalesDocumentResource($document), __(':number deleted. You can restore it from the Restore page.', ['number' => $document->number]));
    }

    public function restore(Request $request, SalesDocument $sale): JsonResponse
    {
        $this->allow($request, $sale->type, 'delete');

        $document = $this->deleter->restore($request->user(), $sale);

        return $this->success(new SalesDocumentResource($document), __(':number restored.', ['number' => $document->number]));
    }

    /** How many deleted documents there are of each kind (for the Restore page's tabs), for the kinds this person may restore. */
    public function trashCounts(Request $request): JsonResponse
    {
        $counts = [];

        foreach (SalesDocument::ISSUABLE as $type) {
            $counts[$type] = $request->user()->hasPermission(Permissions::forDocument($type).'.delete')
                ? SalesDocument::onlyTrashed()->where('type', $type)->count()
                : 0;
        }

        return $this->success($counts);
    }

    /** Deletes many at once. Each one follows its own rules: the ones that cannot be deleted are listed with the reason. */
    public function bulkDestroy(Request $request): JsonResponse
    {
        return $this->bulk($request, false);
    }

    public function bulkRestore(Request $request): JsonResponse
    {
        return $this->bulk($request, true);
    }

    private function bulk(Request $request, bool $restoring): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $documents = SalesDocument::withTrashed()->whereIn('id', array_unique($data['ids']))->get()->keyBy('id');
        $done = [];
        $failed = [];

        foreach (array_unique($data['ids']) as $id) {
            $document = $documents->get($id);

            if ($document === null) {
                $failed[] = ['id' => $id, 'number' => null, 'reason' => __('Not found.')];

                continue;
            }

            if (! $request->user()->hasPermission(Permissions::forDocument($document->type).'.delete')) {
                $failed[] = ['id' => $id, 'number' => $document->number, 'reason' => __('You do not have permission to do that.')];

                continue;
            }

            try {
                $restoring ? $this->deleter->restore($request->user(), $document) : $this->deleter->delete($request->user(), $document);
                $done[] = $id;
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $failed[] = ['id' => $id, 'number' => $document->number, 'reason' => (string) collect($exception->errors())->flatten()->first()];
            }
        }

        $message = $restoring
            ? __(':count restored.', ['count' => count($done)])
            : __(':count deleted. You can restore them from the Restore page.', ['count' => count($done)]);

        return $this->success(['done' => $done, 'failed' => $failed], $message);
    }

    private function applyDisplayStatus(Builder $query, string $status): void
    {
        if ($status === 'voided') {
            $query->whereNotNull('voided_at');

            return;
        }

        if ($status === 'draft') {
            $query->whereNull('voided_at')->whereNotNull('draft_at');

            return;
        }

        // A draft is not pending payment yet: it is not a sale until it is issued.
        $query->whereNull('voided_at')->whereNull('draft_at')->where('payment_status', $status);
    }

    /**
     * @return array{all: int, pending: int, paid: int, partial: int, voided: int, draft: int}
     */
    private function listCounts(Builder $base): array
    {
        $live = fn () => (clone $base)->whereNull('voided_at')->whereNull('draft_at');

        return [
            'all' => (clone $base)->count(),
            'pending' => $live()->where('payment_status', 'pending')->count(),
            'paid' => $live()->where('payment_status', 'paid')->count(),
            'partial' => $live()->where('payment_status', 'partial')->count(),
            'voided' => (clone $base)->whereNotNull('voided_at')->count(),
            'draft' => (clone $base)->whereNull('voided_at')->whereNotNull('draft_at')->count(),
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
        // Drafts are not sales yet: no figure counts them.
        $live = (clone $base)->whereNull('voided_at')->whereNull('draft_at');
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
            // An invoice, delivery note or proforma may be dated today's month up to now, or an earlier month on its
            // last day; never the future (its number carries the month). A quotation is no accounting document: it keeps
            // any date, past or future, because its week runs from there. Only editing one is allowed to move it.
            'issued_at' => ['required', 'date', function (string $attribute, mixed $value, \Closure $fail) use ($forUpdate) {
                if ($forUpdate || request()->input('type') === 'quotation') {
                    return;
                }

                $problem = IssueDate::problem(IssueDate::parse((string) $value));

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
            'customer_id' => ['nullable', 'integer'],
            'save_customer' => ['nullable', 'boolean'],
            'client_code' => ['nullable', 'string', 'max:32'],
            // Every document names its client, except a delivery receipt, which names nobody (the server clears it).
            'client_name' => [$forUpdate ? 'required' : 'required_unless:type,nota_entrega', 'nullable', 'string', 'max:180'],
            'client_company' => ['nullable', 'string', 'max:180'],
            'client_phone' => ['nullable', 'string', 'max:40'],
            'client_nif' => ['nullable', 'string', 'max:32'],
            'client_nie' => ['nullable', 'string', 'max:32'],
            'client_address' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email:rfc', 'max:190'],
            // A discount on the whole bill: a percentage, or an amount in cents.
            'discount_type' => ['nullable', Rule::in(['percent', 'amount'])],
            'discount_value' => ['nullable', 'required_with:discount_type', 'numeric', 'min:0', 'max:'.Pricing::MAX_UNIT_CENTS],
            // A rate from Settings. The server works out the amount. Invoices and quotations only.
            'recargo_rate_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.article' => ['required', 'string', 'max:180'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['required', 'integer', 'min:0', 'max:'.Pricing::MAX_UNIT_CENTS],
            'lines.*.discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.iva_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];

        if ($forUpdate) {
            return $rules;
        }

        return array_merge($rules, [
            'type' => ['required', Rule::in(SalesDocument::ISSUABLE)],
            'draft' => ['nullable', 'boolean'],
            'payment_status' => ['required', Rule::in(SalesDocument::MARKABLE_PAYMENTS)],
            'payment_method_id' => ['nullable', 'integer'],
        ]);
    }
}
