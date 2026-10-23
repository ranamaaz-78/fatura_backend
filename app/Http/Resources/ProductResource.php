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
            'article' => $this->article,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),
            'brand' => $this->brand,
            'short_code' => $this->short_code,
            'barcode' => $this->barcode,
            'barcode_generated' => $this->barcode_generated,
            // Labels: how many times it was sent to print, when last, and whether it ever was.
            'label_print_count' => (int) $this->label_print_count,
            'label_last_printed_at' => $this->label_last_printed_at?->toIso8601String(),
            'label_printed' => (int) $this->label_print_count > 0,
            // Cents, so the client never has to undo a rounded decimal.
            'buying_price' => $this->buying_price,
            // The buying price before the latest change. Null until it has changed once.
            'last_buying_price' => $this->last_buying_price,
            'selling_price' => $this->selling_price,
            'margin_percent' => (float) $this->margin_percent,
            'iva_percent' => (float) $this->iva_percent,
            'minimum_stock' => $this->minimum_stock,
        ];
    }
}
