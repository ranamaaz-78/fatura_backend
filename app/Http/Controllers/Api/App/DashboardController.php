<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Customer;
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

class DashboardController extends Controller
{
    use ApiResponse;

    private const SALE_TYPES = ['factura', 'albaran', 'proforma'];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        $subscription = $company?->activeSubscription;
        $month = ReportPeriod::range('month', null, null);

        $paidDocuments = (int) SalesDocument::query()
            ->whereIn('type', ['factura', 'albaran'])
            ->whereNull('from_settlement_id')
            ->where('payment_status', 'paid')
            ->whereNull('voided_at')
            ->whereBetween('issued_at', $month)
            ->sum('total_cents');

        $proformaSettlements = (int) SalesDocumentSettlement::query()
            ->whereBetween('created_at', $month)
            ->sum('total_cents');

        $monthBase = $this->saleDocuments($month)
            ->leftJoinSub($this->settledSub(), 'settled', 'settled.sales_document_id', '=', 'sales_documents.id');

        $monthTotals = (clone $monthBase)
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status = \'paid\' THEN 1 ELSE 0 END) as paid_count')
            ->selectRaw('SUM(CASE WHEN sales_documents.payment_status <> \'paid\' THEN 1 ELSE 0 END) as open_count')
            ->selectRaw('COALESCE(SUM(sales_documents.total_cents), 0) as total_cents')
            ->selectRaw($this->outstandingSumSql().' as outstanding_cents')
            ->first();

        $openBase = $this->saleDocuments(null)
            ->whereIn('sales_documents.payment_status', ['pending', 'partial'])
            ->leftJoinSub($this->settledSub(), 'settled', 'settled.sales_document_id', '=', 'sales_documents.id');

        $openTotals = (clone $openBase)
            ->selectRaw('COUNT(*) as open_count')
            ->selectRaw($this->outstandingSumSql().' as outstanding_cents')
            ->first();

        $series = (clone $monthBase)
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

        $breakdown = (clone $monthBase)
            ->select('sales_documents.type')
            ->selectRaw('COUNT(*) as document_count')
            ->selectRaw('COALESCE(SUM(sales_documents.total_cents), 0) as total_cents')
            ->groupBy('sales_documents.type')
            ->orderBy('sales_documents.type')
            ->get()
            ->map(fn ($row) => [
                'key' => (string) $row->type,
                'count' => (int) $row->document_count,
                'total_cents' => (int) $row->total_cents,
            ])
            ->all();

        $status = (clone $monthBase)
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

        $openRows = (clone $openBase)
            ->select([
                'sales_documents.id',
                'sales_documents.type',
                'sales_documents.number',
                'sales_documents.client_name',
                'sales_documents.issued_at',
                'sales_documents.payment_status',
                'sales_documents.total_cents',
            ])
            ->selectRaw($this->outstandingExpr().' as outstanding_cents')
            ->orderByDesc('outstanding_cents')
            ->orderByDesc('sales_documents.id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => $this->documentRow($row, (int) $row->outstanding_cents))
            ->all();

        $recent = SalesDocument::query()
            ->whereIn('type', SalesDocument::ISSUABLE)
            ->whereNull('voided_at')
            ->leftJoinSub($this->settledSub(), 'settled', 'settled.sales_document_id', '=', 'sales_documents.id')
            ->select([
                'sales_documents.id',
                'sales_documents.type',
                'sales_documents.number',
                'sales_documents.client_name',
                'sales_documents.issued_at',
                'sales_documents.payment_status',
                'sales_documents.total_cents',
            ])
            ->selectRaw($this->outstandingExpr().' as outstanding_cents')
            ->orderByDesc('sales_documents.issued_at')
            ->orderByDesc('sales_documents.id')
            ->limit(8)
            ->get()
            ->map(fn ($row) => $this->documentRow($row, (int) $row->outstanding_cents))
            ->all();

        $margins = $this->saleDocuments($month)
            ->join('sales_document_lines', 'sales_document_lines.sales_document_id', '=', 'sales_documents.id')
            ->leftJoin('products', 'products.id', '=', 'sales_document_lines.product_id')
            ->selectRaw('COALESCE(SUM(sales_document_lines.base_cents), 0) as base_cents')
            ->selectRaw('COALESCE(SUM(sales_document_lines.tax_cents), 0) as tax_cents')
            ->selectRaw('COALESCE(SUM(sales_document_lines.quantity * COALESCE(products.buying_price, 0)), 0) as cost_cents')
            ->first();

        $baseCents = (int) ($margins->base_cents ?? 0);
        $taxCents = (int) ($margins->tax_cents ?? 0);
        $costCents = (int) ($margins->cost_cents ?? 0);

        $lowStock = Product::query()
            ->whereColumn('quantity', '<=', 'minimum_stock')
            ->orderBy('quantity')
            ->orderBy('article')
            ->limit(5)
            ->get(['id', 'article', 'quantity', 'minimum_stock'])
            ->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'article' => (string) $product->article,
                'quantity' => (int) $product->quantity,
                'minimum_stock' => (int) $product->minimum_stock,
                'band' => (int) $product->quantity <= 0 ? 'out' : 'low',
            ])
            ->all();

        return $this->success([
            'company' => [
                'id' => $company?->id,
                'name' => $company?->name,
                'currency' => $company?->currency ?? 'EUR',
            ],
            'kpis' => [
                'outstanding' => (int) ($openTotals->outstanding_cents ?? 0),
                'paid_this_month' => $paidDocuments + $proformaSettlements,
                'overdue' => 0,
                'invoices_this_month' => (int) SalesDocument::query()
                    ->where('type', 'factura')
                    ->whereNull('voided_at')
                    ->whereBetween('issued_at', $month)
                    ->count(),
                'clients' => (int) Customer::query()->count(),
                'document_count' => (int) ($monthTotals->document_count ?? 0),
                'paid_count' => (int) ($monthTotals->paid_count ?? 0),
                'open_count' => (int) ($openTotals->open_count ?? 0),
                'total_cents' => (int) ($monthTotals->total_cents ?? 0),
                'base_cents' => $baseCents,
                'tax_cents' => $taxCents,
                'cost_cents' => $costCents,
                'profit_cents' => $baseCents - $costCents,
                'low_count' => (int) Product::query()->lowStock()->count(),
                'out_count' => (int) Product::query()->outOfStock()->count(),
            ],
            'series' => $series,
            'breakdown' => $breakdown,
            'status' => $status,
            'recent' => $recent,
            'open_documents' => $openRows,
            'low_stock' => $lowStock,
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
        ]);
    }

    /**
     * @param  array{0: CarbonInterface, 1: CarbonInterface}|null  $range
     */
    private function saleDocuments(?array $range): Builder
    {
        return SalesDocument::query()
            ->whereIn('sales_documents.type', self::SALE_TYPES)
            ->whereNull('sales_documents.from_settlement_id')
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

    private function outstandingExpr(): string
    {
        return 'CASE WHEN sales_documents.payment_status = \'paid\' THEN 0 WHEN sales_documents.total_cents - sales_documents.returned_cents > COALESCE(settled.settled_cents, 0) THEN sales_documents.total_cents - sales_documents.returned_cents - COALESCE(settled.settled_cents, 0) ELSE 0 END';
    }

    private function outstandingSumSql(): string
    {
        return 'COALESCE(SUM('.$this->outstandingExpr().'), 0)';
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRow(object $row, int $outstandingCents): array
    {
        return [
            'id' => (int) $row->id,
            'type' => (string) $row->type,
            'number' => (string) $row->number,
            'client_name' => (string) $row->client_name,
            'issued_at' => $row->issued_at?->toIso8601String(),
            'payment_status' => (string) $row->payment_status,
            'total_cents' => (int) $row->total_cents,
            'outstanding_cents' => $outstandingCents,
        ];
    }
}
