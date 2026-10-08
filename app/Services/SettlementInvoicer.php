<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\SalesDocumentSettlement;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Makes the invoice for one payment on a proforma: the pieces and prices of that payment, paid with the
 * method it was paid with. The proforma already took the stock out, so the invoice moves none.
 */
class SettlementInvoicer
{
    public function __construct(private readonly SaleIssuer $issuer) {}

    /**
     * @param  array<int|string, float|int|string|null>  $ivaByLine  IVA percent per proforma line id, from the form
     */
    public function invoice(User $user, SalesDocument $proforma, SalesDocumentSettlement $settlement, array $ivaByLine = [], ?int $recargoRateId = null): SalesDocument
    {
        return DB::transaction(function () use ($user, $proforma, $settlement, $ivaByLine, $recargoRateId) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()->whereKey($proforma->id)->lockForUpdate()->firstOrFail();

            if ($document->type !== 'proforma') {
                throw ValidationException::withMessages(['type' => __('Only a proforma payment can be invoiced.')]);
            }

            /** @var SalesDocumentSettlement $locked */
            $locked = SalesDocumentSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->sales_document_id !== (int) $document->id) {
                throw ValidationException::withMessages(['settlement' => __('That payment is not on this proforma.')]);
            }

            $existing = $locked->invoice;
            if ($existing !== null && ! $existing->isVoided()) {
                throw ValidationException::withMessages([
                    'settlement' => __('This payment already has invoice :number.', ['number' => $existing->number]),
                ]);
            }

            $locked->load('lines.documentLine');
            $companyId = (int) $document->company_id;

            $products = Product::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('id', $locked->lines->pluck('documentLine.product_id')->filter()->all())
                ->get()
                ->keyBy('id');

            $defaultIva = (float) (TaxRate::withoutGlobalScopes()->where('company_id', $companyId)->max('rate') ?? 21);

            $lines = [];
            foreach ($locked->lines as $row) {
                $line = $row->documentLine;
                $product = $line->product_id !== null ? $products->get($line->product_id) : null;
                $iva = $ivaByLine[$line->id] ?? $ivaByLine[(string) $line->id] ?? null;

                $lines[] = [
                    // A product that has since been deleted still invoices: the line keeps its name and price.
                    'product_id' => $product?->id,
                    'sr_number' => $line->sr_number,
                    'article' => $line->article,
                    'description' => $line->description,
                    'quantity' => (int) $row->quantity,
                    'unit_price' => (int) $row->unit_price,
                    'discount_percent' => 0,
                    'iva_percent' => is_numeric($iva) ? max(0.0, min(100.0, (float) $iva)) : (float) ($product?->iva_percent ?? $defaultIva),
                ];
            }

            $invoice = $this->issuer->issue($user, [
                'type' => 'factura',
                'issued_at' => now(),
                'payment_status' => 'paid',
                'payment_method_id' => $locked->payment_method_id,
                'from_settlement_id' => $locked->id,
                'recargo_rate_id' => $recargoRateId,
                'customer_id' => $document->customer_id,
                'save_customer' => false,
                'client_code' => $document->client_code,
                'client_name' => $document->client_name,
                'client_company' => $document->client_company,
                'client_phone' => $document->client_phone,
                'client_nif' => $document->client_nif,
                'client_nie' => $document->client_nie,
                'client_address' => $document->client_address,
                'lines' => $lines,
            ]);

            $locked->update(['invoice_id' => $invoice->id]);

            return $invoice->fresh(['lines', 'paymentMethod', 'fromSettlement.document']);
        });
    }
}
