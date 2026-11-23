<?php

namespace App\Services;

use App\Models\CompanyPaymentMethod;
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
    /**
     * @param  array<string, mixed>  $clientFill  Details the delivery note lacks (company, phone, nif, address, email). Only a
     *                                           blank field is filled; what the note already says stays as it is. With
     *                                           edit_client the client is fully editable: every detail, the name included,
     *                                           is taken as sent (a blank one clears it).
     */
    public function invoice(User $user, SalesDocument $albaran, array $ivaByLine = [], ?int $recargoRateId = null, array $clientFill = [], bool $payDifference = false, mixed $differenceMethodId = null): SalesDocument
    {
        return DB::transaction(function () use ($user, $albaran, $ivaByLine, $recargoRateId, $clientFill, $payDifference, $differenceMethodId) {
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
                    'article' => $line->article,
                    'description' => $line->description,
                    'quantity' => (int) $line->quantity,
                    'unit_price' => (int) $line->unit_price,
                    'discount_percent' => $line->discount_percent,
                    'iva_percent' => is_numeric($iva) ? max(0.0, min(100.0, (float) $iva)) : (float) ($product?->iva_percent ?? $defaultIva),
                ];
            }

            $full = (bool) ($clientFill['edit_client'] ?? false);
            $typed = fn (string $key): ?string => filled($clientFill[$key] ?? null) ? trim((string) $clientFill[$key]) : null;
            $fill = fn (?string $have, string $key): ?string => $full ? $typed($key) : (filled($have) ? $have : $typed($key));
            // A tax number already on the note, as NIF or NIE, is never replaced.
            $hasTaxId = filled($document->client_nif) || filled($document->client_nie);

            $invoice = $this->issuer->issue($user, [
                'type' => 'factura',
                'issued_at' => now(),
                // Made pending; whether it is paid is settled just below, once IVA and recargo have made its total.
                'payment_status' => 'pending',
                'payment_method_id' => null,
                'from_document_id' => $document->id,
                'recargo_rate_id' => $recargoRateId,
                'customer_id' => $document->customer_id,
                'save_customer' => false,
                'client_code' => $document->client_code,
                'client_name' => $full ? ($typed('client_name') ?? $document->client_name) : $document->client_name,
                'client_company' => $fill($document->client_company, 'client_company'),
                'client_phone' => $fill($document->client_phone, 'client_phone'),
                'client_nif' => $full ? $typed('client_nif') : ($hasTaxId ? $document->client_nif : $typed('client_nif')),
                'client_nie' => $full ? null : $document->client_nie,
                'client_address' => $fill($document->client_address, 'client_address'),
                'client_email' => $fill($document->client_email, 'client_email'),
                'discount_type' => $document->discount_type,
                'discount_value' => $document->discount_type === 'amount' ? (int) round($document->discount_value) : $document->discount_value,
                'lines' => $lines,
            ]);

            // A paid delivery note has paid its own price. The invoice adds IVA and recargo on top, so the rest is
            // still owed: it is paid now (with the method chosen for it) or the invoice stays pending until it is.
            // A delivery note still unpaid makes a pending invoice, as before.
            if ($document->payment_status === 'paid') {
                $difference = max(0, (int) $invoice->total_cents - (int) $document->total_cents);

                if ($difference === 0) {
                    $invoice->forceFill(['payment_status' => 'paid', 'payment_method_id' => $document->payment_method_id])->save();
                } elseif ($payDifference) {
                    $invoice->forceFill([
                        'payment_status' => 'paid',
                        'payment_method_id' => CompanyPaymentMethod::requireActive($companyId, $differenceMethodId),
                    ])->save();
                }
            }

            $document->update([
                'converted_to_id' => $invoice->id,
                'converted_at' => now(),
            ]);

            return $invoice->fresh(['lines', 'paymentMethod', 'fromDocument']);
        });
    }
}
