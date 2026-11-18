<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierBillResource;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierBillPayment;
use App\Services\SupplierLedger;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The supplier ledger: a record book of what was bought from each supplier and what was paid for it. */
class SupplierLedgerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SupplierLedger $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['all', 'active', 'voided'])],
            'payment' => ['nullable', Rule::in(['all', 'unpaid', 'partial', 'paid'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $query = SupplierBill::query()
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $id) => $q->where('supplier_id', $id))
            ->when($filters['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('bill_date', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('bill_date', '<=', $date))
            ->when(($filters['status'] ?? 'all') === 'active', fn (Builder $q) => $q->whereNull('voided_at'))
            ->when(($filters['status'] ?? 'all') === 'voided', fn (Builder $q) => $q->whereNotNull('voided_at'))
            // Cancelled entries are not owed, so a payment filter looks at the active ones only.
            ->when(in_array($filters['payment'] ?? 'all', ['unpaid', 'partial', 'paid'], true), function (Builder $q) use ($filters) {
                $q->whereNull('voided_at');
                match ($filters['payment']) {
                    'unpaid' => $q->where('paid_cents', 0)->whereColumn('amount_cents', '>', 'paid_cents'),
                    'partial' => $q->where('paid_cents', '>', 0)->whereColumn('amount_cents', '>', 'paid_cents'),
                    'paid' => $q->whereColumn('paid_cents', '>=', 'amount_cents'),
                };
            })
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.$search.'%';
                $q->where(function (Builder $inner) use ($like) {
                    $inner->where('number', 'like', $like)
                        ->orWhere('supplier_name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('reference', 'like', $like);
                });
            });

        $page = (clone $query)
            ->with('purchase:id,number,iva_mode,iva_percent,base_cents,iva_cents')
            ->withCount('payments')
            ->withMax('payments', 'paid_on')
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10);

        $monthStart = now()->startOfMonth()->toDateString();
        $active = SupplierBill::query()->whereNull('voided_at');

        return $this->success([
            'items' => SupplierBillResource::collection($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'stats' => [
                // Everything still owed, whatever the month; and this month's entries and payments.
                'owed_cents' => (int) (clone $active)->whereColumn('amount_cents', '>', 'paid_cents')->sum(DB::raw('amount_cents - paid_cents')),
                'month_bought_cents' => (int) (clone $active)->where('bill_date', '>=', $monthStart)->sum('amount_cents'),
                'month_paid_cents' => (int) SupplierBillPayment::query()
                    ->whereHas('bill', fn (Builder $bill) => $bill->whereNull('voided_at'))
                    ->where('paid_on', '>=', $monthStart)
                    ->sum('amount_cents'),
                'open_count' => (clone $active)->whereColumn('amount_cents', '>', 'paid_cents')->count(),
            ],
        ]);
    }

    public function show(SupplierBill $bill): JsonResponse
    {
        return $this->success(new SupplierBillResource($bill->load(['payments.paymentMethod', 'payments.author:id,name', 'creator:id,name', 'purchase:id,number,iva_mode,iva_percent,base_cents,iva_cents'])));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;

        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'bill_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'min:2', 'max:500'],
            'reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            // What was paid when it was written down (0 or missing = nothing yet); the rest is paid later.
            'paid_cents' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'payment_method_id' => ['nullable', 'integer'],
        ]);

        $bill = $this->ledger->record($request->user(), $data);

        return $this->success(new SupplierBillResource($bill), __('Entry saved.'), 201);
    }

    public function addPayment(Request $request, SupplierBill $bill): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'payment_method_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->success(new SupplierBillResource($this->ledger->addPayment($request->user(), $bill, $data)), __('Payment saved.'), 201);
    }

    public function removePayment(SupplierBill $bill, SupplierBillPayment $payment): JsonResponse
    {
        return $this->success(new SupplierBillResource($this->ledger->removePayment($bill, $payment)), __('Payment removed.'));
    }

    public function void(Request $request, SupplierBill $bill): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);

        return $this->success(new SupplierBillResource($this->ledger->void($request->user(), $bill, $data['reason'])), __('Entry cancelled.'));
    }

    /** Every supplier with entries: what was bought, what was paid and what is still owed. Who is owed most comes first. */
    public function suppliers(): JsonResponse
    {
        // The day each supplier was last paid.
        $lastPaid = SupplierBillPayment::query()
            ->join('supplier_bills', 'supplier_bills.id', '=', 'supplier_bill_payments.supplier_bill_id')
            ->whereNull('supplier_bills.voided_at')
            ->whereNotNull('supplier_bills.supplier_id')
            ->groupBy('supplier_bills.supplier_id')
            ->selectRaw('supplier_bills.supplier_id as supplier_id, MAX(supplier_bill_payments.paid_on) as last_paid_on')
            ->pluck('last_paid_on', 'supplier_id')
            ->map(fn ($day) => substr((string) $day, 0, 10))
            ->all();

        $rows = SupplierBill::query()
            ->whereNull('voided_at')
            ->whereNotNull('supplier_id')
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) as entries_count, SUM(amount_cents) as bought_cents, SUM(paid_cents) as paid_cents, MAX(supplier_name) as supplier_name, MAX(bill_date) as last_bill_date, MIN(CASE WHEN amount_cents > paid_cents THEN bill_date END) as oldest_open_on')
            ->get()
            ->map(fn ($row) => [
                'supplier_id' => (int) $row->supplier_id,
                'supplier_name' => $row->supplier_name,
                'entries_count' => (int) $row->entries_count,
                'bought_cents' => (int) $row->bought_cents,
                'paid_cents' => (int) $row->paid_cents,
                'owed_cents' => max(0, (int) $row->bought_cents - (int) $row->paid_cents),
                'last_bill_date' => $row->last_bill_date ? substr((string) $row->last_bill_date, 0, 10) : null,
                'oldest_open_on' => $row->oldest_open_on ? substr((string) $row->oldest_open_on, 0, 10) : null,
                'last_paid_on' => $lastPaid[(int) $row->supplier_id] ?? null,
            ])
            ->sortByDesc('owed_cents')
            ->values();

        return $this->success($rows);
    }

    /**
     * One supplier's account, like a ledger page: what was owed when the period began (the opening balance), then every
     * purchase (adds to the balance) and every payment (takes from it) in date order with the running balance, and what
     * is owed at the end. Without dates it is the whole history, from a balance of zero.
     */
    public function statement(Request $request, Supplier $supplier): JsonResponse
    {
        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = $range['from'] ?? null;
        $to = $range['to'] ?? null;

        $bills = SupplierBill::query()
            ->where('supplier_id', $supplier->id)
            ->whereNull('voided_at')
            ->with(['payments.paymentMethod', 'purchase:id,number,iva_mode,iva_percent,base_cents,iva_cents'])
            ->orderBy('bill_date')
            ->orderBy('id')
            ->limit(2000)
            ->get();

        // Every movement of the account: a purchase is "bought", a payment is "paid".
        $moves = [];
        foreach ($bills as $bill) {
            $moves[] = ['date' => $bill->bill_date->toDateString(), 'order' => 0, 'id' => $bill->id, 'type' => 'bill', 'number' => $bill->number, 'details' => $bill->description, 'reference' => $bill->reference, 'bought_cents' => (int) $bill->amount_cents, 'paid_cents' => 0, 'iva_cents' => $this->ivaOf($bill), 'iva_percent' => $this->ivaOf($bill) > 0 ? (float) $bill->purchase->iva_percent : null];

            foreach ($bill->payments as $payment) {
                $moves[] = ['date' => $payment->paid_on->toDateString(), 'order' => 1, 'id' => $payment->id, 'type' => 'payment', 'number' => $bill->number, 'details' => trim(($payment->paymentMethod?->name ?? '').($payment->note ? ' · '.$payment->note : ''), ' ·'), 'reference' => $bill->reference, 'bought_cents' => 0, 'paid_cents' => (int) $payment->amount_cents, 'iva_cents' => 0, 'iva_percent' => null];
            }
        }

        usort($moves, fn (array $a, array $b) => [$a['date'], $a['order'], $a['id']] <=> [$b['date'], $b['order'], $b['id']]);

        $opening = 0;
        $balance = 0;
        $transactions = [];
        foreach ($moves as $move) {
            if ($from !== null && $move['date'] < $from) {
                $opening += $move['bought_cents'] - $move['paid_cents'];
                $balance = $opening;

                continue;
            }

            if ($to !== null && $move['date'] > $to) {
                continue;
            }

            $balance += $move['bought_cents'] - $move['paid_cents'];
            $transactions[] = $move + ['balance_cents' => $balance];
        }

        return $this->success([
            'supplier' => ['id' => $supplier->id, 'name' => $supplier->name, 'code' => $supplier->code],
            'from' => $from,
            'to' => $to,
            'opening_cents' => $opening,
            'bought_cents' => (int) collect($transactions)->sum('bought_cents'),
            'paid_cents' => (int) collect($transactions)->sum('paid_cents'),
            // The IVA inside what was bought in these dates (from purchases written with IVA).
            'iva_cents' => (int) collect($transactions)->sum('iva_cents'),
            'closing_cents' => $balance,
            // Whole-history figures, for the cards on the screen (they do not depend on the dates).
            'owed_cents' => (int) $bills->sum(fn (SupplierBill $bill) => $bill->owedCents()),
            'transactions' => $transactions,
            'entries' => SupplierBillResource::collection($bills->sortByDesc('bill_date')->values()),
        ]);
    }

    /** The IVA inside an entry: only an entry written from a purchase that had IVA has any. */
    private function ivaOf(SupplierBill $bill): int
    {
        $purchase = $bill->purchase;

        return $purchase !== null && ($purchase->iva_mode ?? 'none') !== 'none' ? (int) $purchase->iva_cents : 0;
    }
}
