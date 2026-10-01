<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'phone' => $this->phone,
            'nif' => $this->nif,
            'nie' => $this->nie,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'documents_count' => $this->whenCounted('documents'),
        ];
    }
}
