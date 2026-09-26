<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'mime' => $this->mime,
            'size_bytes' => $this->size_bytes,
            // The authenticated stream. The disk path stays on the server.
            'file_url' => "/app/product-images/{$this->uuid}/file",
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
