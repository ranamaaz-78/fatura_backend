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
            'total_cents' => $this->total_cents,
            'lines' => SalesDocumentLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
