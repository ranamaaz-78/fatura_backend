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
            'total_cents' => $this->total_cents,
            'items_count' => $this->whenCounted('lines'),
            'is_voided' => $this->isVoided(),
            'voided_at' => $this->voided_at?->toIso8601String(),
            'void_reason' => $this->void_reason,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'article' => $line->article,
                'quantity' => $line->quantity,
                'unit_cost' => $line->unit_cost,
                'total_cents' => $line->total_cents,
            ])->values()),
        ];
    }
}
