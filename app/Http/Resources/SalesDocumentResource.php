<?php

namespace App\Http\Resources;

use App\Support\Text;
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
            'verify_code' => $this->type === 'factura' ? $this->verify_code : null,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'is_expired' => $this->isExpired(),
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
            'client_name' => Text::proper($this->client_name),
            'client_company' => Text::proper($this->client_company),
            'client_phone' => $this->client_phone,
            'client_nif' => $this->client_nif,
            'client_nie' => $this->client_nie,
            'client_address' => Text::proper($this->client_address),
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
            'returned_cents' => (int) $this->returned_cents,
            'from_settlement_id' => $this->from_settlement_id,
            'from_document_id' => $this->from_document_id,
            'can_invoice' => $this->canInvoice(),
            'from_proforma' => $this->whenLoaded(
                'fromSettlement',
                fn () => $this->fromSettlement?->document === null
                    ? null
                    : ['id' => $this->fromSettlement->document->id, 'number' => $this->fromSettlement->document->number],
            ),
            'from_document' => $this->when(
                $this->from_document_id !== null,
                fn () => $this->fromDocument === null ? null : ['id' => $this->fromDocument->id, 'number' => $this->fromDocument->number],
            ),
            'is_fully_returned' => $this->isFullyReturned(),
            'is_partial' => $this->payment_status === 'partial',
            'lines' => SalesDocumentLineResource::collection($this->whenLoaded('lines')),
            'settlements' => SalesDocumentSettlementResource::collection($this->whenLoaded('settlements')),
            'returns' => SalesDocumentReturnResource::collection($this->whenLoaded('returns')),
        ];
    }
}
