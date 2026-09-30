<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'number' => $this->number,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'payment_status' => $this->payment_status,
            'payment_method_id' => $this->payment_method_id,
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'is_voided' => $this->isVoided(),
            'converted_to_id' => $this->converted_to_id,
            'converted_at' => $this->converted_at?->toIso8601String(),
            'is_converted' => $this->isConverted(),
            'converted_to' => $this->whenLoaded(
                'convertedTo',
                fn () => $this->convertedTo === null
                    ? null
                    : [
                        'id' => $this->convertedTo->id,
                        'type' => $this->convertedTo->type,
                        'number' => $this->convertedTo->number,
                    ],
            ),
            'payment_method' => $this->whenLoaded(
                'paymentMethod',
                fn () => $this->paymentMethod === null
                    ? null
                    : ['id' => $this->paymentMethod->id, 'name' => $this->paymentMethod->name],
            ),
            'customer_id' => $this->customer_id,
            'client_code' => $this->client_code,
            'client_name' => $this->client_name,
            'client_company' => $this->client_company,
            'client_phone' => $this->client_phone,
            'client_nif' => $this->client_nif,
            'client_nie' => $this->client_nie,
            'notes' => $this->notes,
            'base_cents' => $this->base_cents,
            'tax_cents' => $this->tax_cents,
            // base_cents is the taxable base after the bill discount; base + discount is the subtotal.
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_cents' => (int) $this->discount_cents,
            'recargo_percent' => $this->recargo_percent,
            'recargo_cents' => (int) $this->recargo_cents,
            'total_cents' => $this->total_cents,
            'settled_cents' => $this->settledCents(),
            'is_partial' => $this->payment_status === 'partial',
            'lines' => SalesDocumentLineResource::collection($this->whenLoaded('lines')),
            'settlements' => SalesDocumentSettlementResource::collection($this->whenLoaded('settlements')),
        ];
    }
}
