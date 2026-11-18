<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,
            'purchased_on' => $this->purchased_on?->toDateString(),
            'supplier_invoice_no' => $this->supplier_invoice_no,
            'notes' => $this->notes,
            // IVA on the whole purchase: none, excluded (added on top) or included (already in the prices).
            'iva_mode' => $this->iva_mode ?? 'none',
            'iva_percent' => (float) ($this->iva_percent ?? 0),
            'base_cents' => (int) ($this->base_cents ?? $this->total_cents),
            'iva_cents' => (int) ($this->iva_cents ?? 0),
            // What is owed to the supplier: IVA included.
            'total_cents' => $this->total_cents,
            'items_count' => $this->whenCounted('lines'),
            // Where this purchase stands in Supplier Payments (when the purchase is opened).
            'ledger_entry' => $this->whenLoaded('ledgerEntry', fn () => $this->ledgerEntry === null ? null : [
                'id' => $this->ledgerEntry->id,
                'number' => $this->ledgerEntry->number,
                'paid_cents' => $this->ledgerEntry->paid_cents,
                'owed_cents' => $this->ledgerEntry->owedCents(),
                'payment_status' => $this->ledgerEntry->paymentStatus(),
                'is_voided' => $this->ledgerEntry->isVoided(),
            ]),
            'is_voided' => $this->isVoided(),
            // It can be changed until something is paid for it in Supplier Payments (known when the purchase is opened).
            'can_edit' => $this->whenLoaded('ledgerEntry', fn () => ! $this->isVoided() && ($this->ledgerEntry === null || (int) $this->ledgerEntry->paid_cents === 0)),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'article' => $line->article,
                // The product's barcode today (null when the product is gone).
                'barcode' => $line->relationLoaded('product') ? $line->product?->barcode : null,
                'quantity' => $line->quantity,
                'unit_cost' => $line->unit_cost,
                'total_cents' => $line->total_cents,
            ])->values()),
        ];
    }
}
