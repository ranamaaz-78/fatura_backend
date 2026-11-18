<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,
            // The purchase this entry was written from, if any.
            'purchase_id' => $this->purchase_id,
            'purchase_number' => $this->whenLoaded('purchase', fn () => $this->purchase?->number),
            // The IVA inside the amount, when the entry came from a purchase that had IVA: its rate, its base and the tax.
            'iva' => $this->whenLoaded('purchase', fn () => $this->purchase !== null && ($this->purchase->iva_mode ?? 'none') !== 'none' && (int) $this->purchase->iva_cents > 0 ? [
                'mode' => $this->purchase->iva_mode,
                'percent' => (float) $this->purchase->iva_percent,
                'base_cents' => (int) $this->purchase->base_cents,
                'iva_cents' => (int) $this->purchase->iva_cents,
            ] : null),
            'bill_date' => $this->bill_date?->toDateString(),
            'description' => $this->description,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'amount_cents' => $this->amount_cents,
            'paid_cents' => $this->paid_cents,
            'owed_cents' => $this->owedCents(),
            'payment_status' => $this->paymentStatus(),
            // The day of the last payment and how many there are (a list asks for both with the entries).
            'last_paid_on' => ($last = $this->payments_max_paid_on ?? ($this->relationLoaded('payments') ? $this->payments->max('paid_on') : null)) ? substr((string) $last, 0, 10) : null,
            'payments_count' => $this->whenCounted('payments'),
            'is_voided' => $this->isVoided(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'paid_on' => $payment->paid_on?->toDateString(),
                'amount_cents' => $payment->amount_cents,
                'payment_method_id' => $payment->payment_method_id,
                'payment_method_name' => $payment->paymentMethod?->name,
                'note' => $payment->note,
                'created_by_name' => $payment->author?->name,
            ])->values()),
        ];
    }
}
