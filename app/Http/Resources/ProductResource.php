<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sr_number' => $this->sr_number,
            'article' => $this->article,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'brand' => $this->brand,
            'image_code' => $this->image_code,
            'barcode' => $this->barcode,
            'barcode_generated' => $this->barcode_generated,
            // Cents, so the client never has to undo a rounded decimal.
            'buying_price' => $this->buying_price,
            'selling_price' => $this->selling_price,
            'margin_percent' => (float) $this->margin_percent,
            'iva_percent' => (float) $this->iva_percent,
            'minimum_stock' => $this->minimum_stock,
        ];
    }
}
