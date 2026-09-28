<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyPaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'documents_count' => $this->whenCounted('documents'),
            'settlements_count' => $this->whenCounted('settlements'),
        ];
    }
}
