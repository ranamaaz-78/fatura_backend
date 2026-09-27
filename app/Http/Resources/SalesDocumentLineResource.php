<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesDocumentLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'position' => $this->position,
            'product_id' => $this->product_id,
            'sr_number' => $this->sr_number,
            'article' => $this->article,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount_percent' => (float) $this->discount_percent,
            'iva_percent' => (float) $this->iva_percent,
            'base_cents' => $this->base_cents,
            'tax_cents' => $this->tax_cents,
            'total_cents' => $this->total_cents,
        ];
    }
}
