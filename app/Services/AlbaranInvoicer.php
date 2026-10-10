<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a delivery note into an invoice: the same customer, pieces and prices, with IVA (and recargo de
 * equivalencia, when chosen) added on top, since a delivery note is issued without tax. The delivery note
 * already took the stock out and already counts as the sale, so the invoice moves none and adds none.
 */
class AlbaranInvoicer
{
    public function __construct(private readonly SaleIssuer $issuer) {}

    /**
     * @param  array<int|string, float|int|string|null>  $ivaByLine  IVA percent per delivery-note line id, from the form
     */
    public function invoice(User $user, SalesDocument $albaran, array $ivaByLine = [], ?int $recargoRateId = null): SalesDocument
    {
        return DB::transaction(function () use ($user, $albaran, $ivaByLine, $recargoRateId) {
            /** @var SalesDocument $document */
            $document = SalesDocument::query()->whereKey($albaran->id)->lockForUpdate()->firstOrFail();

            if ($document->type !== 'albaran') {
                throw ValidationException::withMessages(['type' => __('Only a delivery note can be turned into an invoice here.')]);
            }

            if ($document->isVoided()) {
                throw ValidationException::withMessages(['type' => __('A voided delivery note cannot be invoiced.')]);
            }

            if (! $document->canInvoice()) {
                $existing = $document->convertedTo;

                throw ValidationException::withMessages([
                    'type' => __('This delivery note already has invoice :number.', ['number' => $existing?->number ?? '']),
                ]);
            }

            $document->load('lines');
            $companyId = (int) $document->company_id;

            $products = Product::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereIn('id', $document->lines->pluck('product_id')->filter()->all())
                ->get()
                ->keyBy('id');

            $defaultIva = (float) (TaxRate::withoutGlobalScopes()->where('company_id', $companyId)->max('rate') ?? 21);

            $lines = [];
            foreach ($document->lines as $line) {
                $product = $line->product_id !== null ? $products->get($line->product_id) : null;
                $iva = $ivaByLine[$line->id] ?? $ivaByLine[(string) $line->id] ?? null;

                $lines[] = [
                    // A product that has since been deleted still invoices: the line keeps its name and price.
                    'product_id' => $product?->id,
                    'sr_number' => $line->sr_number,
                    'article' => $line->article,
                    'description' => $line->description,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => (int) $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'iva_percent' => is_numeric($iva) ? max(0.0, min(100.0, (float) $iva)) : (float) ($product?->iva_percent ?? $defaultIva),
                ];
            }

            $invoice = $this->issuer->issue($user, [
                'type' => 'factura',
                'issued_at' => now(),
                // As the delivery note stands: paid with the method it was paid with, or still owed.
                'payment_status' => $document->payment_status === 'paid' ? 'paid' : 'pending',
                'payment_method_id' => $document->payment_method_id,
                'from_document_id' => $document->id,
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
                'discount_type' => $document->discount_type,
                'discount_value' => $document->discount_type === 'amount' ? (int) round($document->discount_value) : $document->discount_value,
                'lines' => $lines,
            ]);

            $document->update([
                'converted_to_id' => $invoice->id,
                'converted_at' => now(),
            ]);

            return $invoice->fresh(['lines', 'paymentMethod', 'fromDocument']);
        });
    }
}
