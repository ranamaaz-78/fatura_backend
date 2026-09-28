<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\SalesDocumentSettlement;
use App\Support\ReportPeriod;
use App\Traits\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    use ApiResponse;

    private const KINDS = [
        'sales',
        'tax',
        'products',
        'outstanding',
        'payments',
        'stock',
        'clients',
        'suppliers',
    ];

    private const SALE_TYPES = ['factura', 'albaran', 'proforma'];

    public function show(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['all', 'month', 'week', 'day', 'custom'])],
            'from' => ['required_if:period,custom', 'nullable', 'date'],
            'to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:from'],
        ]);

        $period = $filters['period'] ?? 'all';
        $range = in_array($kind, ['stock', 'suppliers'], true)
            ? null
            : ReportPeriod::range($period, $filters['from'] ?? null, $filters['to'] ?? null);

        $data = match ($kind) {
            'sales' => $this->sales($range),
            'tax' => $this->tax($range),
            'products' => $this->products($range),
            'outstanding' => $this->outstanding($range),
            'payments' => $this->payments($range),
            'stock' => $this->stock(),
            'clients' => $this->clients($range),
            'suppliers' => $this->suppliers(),
        };

        return $this->success(['kind' => $kind, ...$data]);
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     * @return array<string, mixed>
     */
    private function sales(?array $range): array
    {
        $base = $this->saleDocuments($range)->leftJoinSub($this->settledSub(), 'settled', 'settled.sales_document_id', '=', 'sales_documents.id');

        $totals = (clone $base)
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('COUNT(DISTINCT sales_documents.client_name) as client_count')
            ->selectRaw('COALESCE(SUM(sales_documents.total_cents), 0) as total_cents')
            ->selectRaw($this->paidSumSql().' as paid_cents')
            ->selectRaw($this->outstandingSumSql().' as outstanding_cents')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status = \'paid\' THEN 1 ELSE 0 END) as paid_count')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status = \'partial\' THEN 1 ELSE 0 END) as partial_count')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status = \'pending\' THEN 1 ELSE 0 END) as pending_count')
            ->first();

        $series = (clone $base)
            ->selectRaw('DATE(sales_documents.issued_at) as day')
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('COALESCE(SUM(sales_documents.total_cents), 0) as total_cents')
            ->groupByRaw('DATE(sales_documents.issued_at)')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => [
                'day' => (string) $row->day,
                'document_count' => (int) $row->document_count,
                'total_cents' => (int) $row->total_cents,
            ])
            ->all();

        $breakdown = (clone $base)
            ->select('sales_documents.type')
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('COALESCE(SUM(sales_documents.total_cents), 0) as total_cents')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status = \'paid\' THEN 1 ELSE 0 END) as paid_count')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status <> \'paid\' THEN 1 ELSE 0 END) as open_count')
            ->groupBy('sales_documents.type')
            ->orderBy('sales_documents.type')
            ->get()
            ->map(fn ($row) => [
                'key' => (string) $row->type,
                'label' => (string) $row->type,
                'count' => (int) $row->document_count,
                'paid_count' => (int) $row->paid_count,
                'open_count' => (int) $row->open_count,
                'total_cents' => (int) $row->total_cents,
            ])
            ->all();

        $status = (clone $base)
            ->select('sales_documents.payment_status')
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('COALESCE(SUM(sales_documents.total_cents), 0) as total_cents')
            ->groupBy('sales_documents.payment_status')
            ->get()
            ->map(fn ($row) => [
                'key' => (string) $row->payment_status,
                'count' => (int) $row->document_count,
                'total_cents' => (int) $row->total_cents,
            ])
            ->all();

        return [
            'kpis' => [
                'document_count' => (int) ($totals->document_count ?? 0),
                'client_count' => (int) ($totals->client_count ?? 0),
                'paid_count' => (int) ($totals->paid_count ?? 0),
                'partial_count' => (int) ($totals->partial_count ?? 0),
                'pending_count' => (int) ($totals->pending_count ?? 0),
                'total_cents' => (int) ($totals->total_cents ?? 0),
                'paid_cents' => (int) ($totals->paid_cents ?? 0),
                'outstanding_cents' => (int) ($totals->outstanding_cents ?? 0),
            ],
            'series' => $series,
            'breakdown' => $breakdown,
            'status' => $status,
        ];
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     * @return array<string, mixed>
     */
    private function tax(?array $range): array
    {
        $rows = $this->saleDocuments($range)
            ->join('sales_document_lines', 'sales_document_lines.sales_document_id', '=', 'sales_documents.id')
            ->selectRaw('sales_document_lines.iva_percent as rate')
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('COUNT(DISTINCT sales_documents.id) as document_count')
            ->selectRaw('COALESCE(SUM(sales_document_lines.base_cents), 0) as base_cents')
            ->selectRaw('COALESCE(SUM(sales_document_lines.tax_cents), 0) as tax_cents')
            ->selectRaw('COALESCE(SUM(sales_document_lines.total_cents), 0) as total_cents')
            ->groupBy('sales_document_lines.iva_percent')
            ->orderByDesc('sales_document_lines.iva_percent')
            ->get()
            ->map(fn ($row) => [
                'rate' => (float) $row->rate,
                'line_count' => (int) $row->line_count,
                'document_count' => (int) $row->document_count,
                'base_cents' => (int) $row->base_cents,
                'tax_cents' => (int) $row->tax_cents,
                'total_cents' => (int) $row->total_cents,
            ]);

        return [
            'kpis' => [
                'base_cents' => (int) $rows->sum('base_cents'),
                'tax_cents' => (int) $rows->sum('tax_cents'),
                'total_cents' => (int) $rows->sum('total_cents'),
                'rate_count' => $rows->count(),
                'line_count' => (int) $rows->sum('line_count'),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     * @return array<string, mixed>
     */
    private function products(?array $range): array
    {
        $rows = $this->saleDocuments($range)
            ->join('sales_document_lines', 'sales_document_lines.sales_document_id', '=', 'sales_documents.id')
            ->selectRaw('sales_document_lines.product_id')
            ->selectRaw('sales_document_lines.article')
            ->selectRaw('MAX(sales_document_lines.description) as description')
            ->selectRaw('SUM(sales_document_lines.quantity) as quantity')
            ->selectRaw('COUNT(DISTINCT sales_documents.id) as document_count')
            ->selectRaw('COALESCE(SUM(sales_document_lines.base_cents), 0) as base_cents')
            ->selectRaw('COALESCE(SUM(sales_document_lines.total_cents), 0) as total_cents')
            ->groupBy('sales_document_lines.product_id', 'sales_document_lines.article')
            ->orderByDesc('quantity')
            ->orderByDesc('total_cents')
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'article' => (string) $row->article,
                'description' => $row->description === null ? null : (string) $row->description,
                'quantity' => (int) $row->quantity,
                'document_count' => (int) $row->document_count,
                'base_cents' => (int) $row->base_cents,
                'total_cents' => (int) $row->total_cents,
            ]);

        return [
            'kpis' => [
                'product_count' => $rows->count(),
                'quantity' => (int) $rows->sum('quantity'),
                'total_cents' => (int) $rows->sum('total_cents'),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     * @return array<string, mixed>
     */
    private function outstanding(?array $range): array
    {
        $rows = $this->saleDocuments($range)
            ->whereIn('sales_documents.payment_status', ['pending', 'partial'])
            ->leftJoinSub($this->settledSub(), 'settled', 'settled.sales_document_id', '=', 'sales_documents.id')
            ->select([
                'sales_documents.id',
                'sales_documents.type',
                'sales_documents.number',
                'sales_documents.client_name',
                'sales_documents.client_company',
                'sales_documents.issued_at',
                'sales_documents.payment_status',
                'sales_documents.total_cents',
            ])
            ->selectRaw($this->outstandingExpr().' as outstanding_cents')
            ->orderByDesc('sales_documents.issued_at')
            ->orderByDesc('sales_documents.id')
            ->get()
            ->map(function ($row) {
                $issued = $row->issued_at;
                $daysOpen = $issued === null
                    ? 0
                    : (int) (clone $issued)->startOfDay()->diffInDays(now()->startOfDay());

                return [
                    'id' => (int) $row->id,
                    'type' => (string) $row->type,
                    'number' => (string) $row->number,
                    'client_name' => (string) $row->client_name,
                    'client_company' => $row->client_company === null ? null : (string) $row->client_company,
                    'issued_at' => $issued?->toIso8601String(),
                    'days_open' => $daysOpen,
                    'payment_status' => (string) $row->payment_status,
                    'total_cents' => (int) $row->total_cents,
                    'outstanding_cents' => (int) $row->outstanding_cents,
                ];
            });

        return [
            'kpis' => [
                'document_count' => $rows->count(),
                'client_count' => $rows->pluck('client_name')->unique()->count(),
                'pending_count' => $rows->where('payment_status', 'pending')->count(),
                'partial_count' => $rows->where('payment_status', 'partial')->count(),
                'outstanding_cents' => (int) $rows->sum('outstanding_cents'),
                'oldest_days' => (int) ($rows->max('days_open') ?? 0),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     * @return array<string, mixed>
     */
    private function payments(?array $range): array
    {
        $rows = $this->saleDocuments($range)
            ->leftJoinSub($this->settledSub(), 'settled', 'settled.sales_document_id', '=', 'sales_documents.id')
            ->leftJoinSub($this->lastMethodSub(), 'last_method', 'last_method.sales_document_id', '=', 'sales_documents.id')
            ->leftJoin('company_payment_methods as methods', 'methods.id', '=', DB::raw('COALESCE(sales_documents.payment_method_id, last_method.payment_method_id)'))
            ->selectRaw('COALESCE(sales_documents.payment_method_id, last_method.payment_method_id) as payment_method_id')
            ->selectRaw('MAX(methods.name) as method_name')
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status <> \'paid\' THEN 1 ELSE 0 END) as open_count')
            ->selectRaw($this->paidSumSql().' as received_cents')
            ->selectRaw($this->outstandingSumSql().' as outstanding_cents')
            ->groupByRaw('COALESCE(sales_documents.payment_method_id, last_method.payment_method_id)')
            ->orderByDesc('received_cents')
            ->get()
            ->map(fn ($row) => [
                'payment_method_id' => $row->payment_method_id === null ? null : (int) $row->payment_method_id,
                'method_name' => $row->method_name === null ? null : (string) $row->method_name,
                'document_count' => (int) $row->document_count,
                'open_count' => (int) $row->open_count,
                'received_cents' => (int) $row->received_cents,
                'outstanding_cents' => (int) $row->outstanding_cents,
            ]);

        return [
            'kpis' => [
                'method_count' => $rows->count(),
                'received_cents' => (int) $rows->sum('received_cents'),
                'outstanding_cents' => (int) $rows->sum('outstanding_cents'),
                'document_count' => (int) $rows->sum('document_count'),
                'open_count' => (int) $rows->sum('open_count'),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stock(): array
    {
        $rank = ['out' => 0, 'low' => 1, 'ok' => 2];

        $rows = Product::query()
            ->get(['id', 'article', 'quantity', 'minimum_stock', 'buying_price'])
            ->map(function (Product $product) {
                $quantity = (int) $product->quantity;
                $minimum = (int) $product->minimum_stock;
                $band = $quantity <= 0 ? 'out' : ($quantity <= $minimum ? 'low' : 'ok');

                return [
                    'id' => (int) $product->id,
                    'article' => (string) $product->article,
                    'quantity' => $quantity,
                    'minimum_stock' => $minimum,
                    'buying_price_cents' => (int) $product->buying_price,
                    'stock_value_cents' => $quantity * (int) $product->buying_price,
                    'band' => $band,
                ];
            })
            ->sort(function (array $left, array $right) use ($rank) {
                $order = $rank[$left['band']] <=> $rank[$right['band']];

                return $order !== 0 ? $order : $right['stock_value_cents'] <=> $left['stock_value_cents'];
            })
            ->values();

        $ok = $rows->where('band', 'ok');
        $low = $rows->where('band', 'low');
        $out = $rows->where('band', 'out');

        return [
            'kpis' => [
                'product_count' => $rows->count(),
                'ok_count' => $ok->count(),
                'low_count' => $low->count(),
                'out_count' => $out->count(),
                'units' => (int) $rows->sum('quantity'),
                'stock_value_cents' => (int) $rows->sum('stock_value_cents'),
            ],
            'breakdown' => [
                [
                    'key' => 'out',
                    'label' => 'out',
                    'count' => $out->count(),
                    'total_cents' => (int) $out->sum('stock_value_cents'),
                ],
                [
                    'key' => 'low',
                    'label' => 'low',
                    'count' => $low->count(),
                    'total_cents' => (int) $low->sum('stock_value_cents'),
                ],
                [
                    'key' => 'ok',
                    'label' => 'ok',
                    'count' => $ok->count(),
                    'total_cents' => (int) $ok->sum('stock_value_cents'),
                ],
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     * @return array<string, mixed>
     */
    private function clients(?array $range): array
    {
        $rows = $this->saleDocuments($range)
            ->leftJoinSub($this->settledSub(), 'settled', 'settled.sales_document_id', '=', 'sales_documents.id')
            ->select('sales_documents.client_name', 'sales_documents.client_company')
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status = \'paid\' THEN 1 ELSE 0 END) as paid_count')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status <> \'paid\' THEN 1 ELSE 0 END) as open_count')
            ->selectRaw('COALESCE(SUM(sales_documents.total_cents), 0) as total_cents')
            ->selectRaw($this->paidSumSql().' as paid_cents')
            ->selectRaw($this->outstandingSumSql().' as outstanding_cents')
            ->groupBy('sales_documents.client_name', 'sales_documents.client_company')
            ->orderByDesc('total_cents')
            ->get()
            ->map(fn ($row) => [
                'client_name' => (string) $row->client_name,
                'client_company' => $row->client_company === null ? null : (string) $row->client_company,
                'document_count' => (int) $row->document_count,
                'paid_count' => (int) $row->paid_count,
                'open_count' => (int) $row->open_count,
                'total_cents' => (int) $row->total_cents,
                'paid_cents' => (int) $row->paid_cents,
                'outstanding_cents' => (int) $row->outstanding_cents,
            ]);

        return [
            'kpis' => [
                'client_count' => $rows->count(),
                'document_count' => (int) $rows->sum('document_count'),
                'total_cents' => (int) $rows->sum('total_cents'),
                'paid_cents' => (int) $rows->sum('paid_cents'),
                'outstanding_cents' => (int) $rows->sum('outstanding_cents'),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suppliers(): array
    {
        $rows = Product::query()
            ->leftJoin('suppliers', 'suppliers.id', '=', 'products.supplier_id')
            ->selectRaw('products.supplier_id')
            ->selectRaw('MAX(suppliers.name) as supplier_name')
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(products.quantity), 0) as quantity')
            ->selectRaw('SUM(CASE WHEN products.quantity <= 0 THEN 1 ELSE 0 END) as out_count')
            ->selectRaw('SUM(CASE WHEN products.quantity > 0 AND products.quantity <= products.minimum_stock THEN 1 ELSE 0 END) as low_count')
            ->selectRaw('COALESCE(SUM(products.quantity * products.buying_price), 0) as stock_value_cents')
            ->groupBy('products.supplier_id')
            ->orderByDesc('stock_value_cents')
            ->get()
            ->map(fn ($row) => [
                'supplier_id' => $row->supplier_id === null ? null : (int) $row->supplier_id,
                'supplier_name' => $row->supplier_name === null ? null : (string) $row->supplier_name,
                'product_count' => (int) $row->product_count,
                'quantity' => (int) $row->quantity,
                'low_count' => (int) $row->low_count,
                'out_count' => (int) $row->out_count,
                'stock_value_cents' => (int) $row->stock_value_cents,
            ]);

        return [
            'kpis' => [
                'supplier_count' => $rows->whereNotNull('supplier_id')->count(),
                'product_count' => (int) $rows->sum('product_count'),
                'quantity' => (int) $rows->sum('quantity'),
                'stock_value_cents' => (int) $rows->sum('stock_value_cents'),
            ],
            'rows' => $rows->all(),
        ];
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     */
    private function saleDocuments(?array $range): Builder
    {
        return SalesDocument::query()
            ->whereIn('sales_documents.type', self::SALE_TYPES)
            ->whereNull('sales_documents.voided_at')
            ->when($range !== null, fn (Builder $query) => $query->whereBetween('sales_documents.issued_at', $range));
    }

    private function settledSub(): QueryBuilder
    {
        return SalesDocumentSettlement::query()
            ->select('sales_document_id')
            ->selectRaw('SUM(total_cents) as settled_cents')
            ->groupBy('sales_document_id')
            ->getQuery();
    }

    private function lastMethodSub(): QueryBuilder
    {
        $latest = SalesDocumentSettlement::query()
            ->select('sales_document_id')
            ->selectRaw('MAX(id) as max_id')
            ->groupBy('sales_document_id');

        return SalesDocumentSettlement::query()
            ->joinSub($latest, 'latest', 'latest.max_id', '=', 'sales_document_settlements.id')
            ->select('sales_document_settlements.sales_document_id', 'sales_document_settlements.payment_method_id')
            ->getQuery();
    }

    private function outstandingExpr(): string
    {
        return 'CASE WHEN sales_documents.payment_status = \'paid\' THEN 0 WHEN sales_documents.total_cents > COALESCE(settled.settled_cents, 0) THEN sales_documents.total_cents - COALESCE(settled.settled_cents, 0) ELSE 0 END';
    }

    private function paidSumSql(): string
    {
        return 'COALESCE(SUM(CASE WHEN sales_documents.payment_status = \'paid\' THEN sales_documents.total_cents ELSE COALESCE(settled.settled_cents, 0) END), 0)';
    }

    private function outstandingSumSql(): string
    {
        return 'COALESCE(SUM('.$this->outstandingExpr().'), 0)';
    }
}
