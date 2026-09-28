<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesDocumentSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_method_id' => $this->payment_method_id,
            'payment_method' => $this->whenLoaded(
                'paymentMethod',
                fn () => $this->paymentMethod === null
                    ? null
                    : ['id' => $this->paymentMethod->id, 'name' => $this->paymentMethod->name],
            ),
            'total_cents' => $this->total_cents,
            'created_at' => $this->created_at?->toIso8601String(),
            'lines' => $this->whenLoaded(
                'lines',
                fn () => $this->lines->map(fn ($line) => [
                    'line_id' => $line->sales_document_line_id,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unit_price,
                    'total_cents' => $line->total_cents,
                ])->values()->all(),
            ),
        ];
    }
}
