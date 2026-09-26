<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'whatsapp_number' => $this->whatsappNumber(),
            'city' => $this->city,
            'country' => $this->country,
            'business_type' => $this->business_type,
            'team_size' => $this->team_size,
            'message' => $this->message,
            'plan_id' => $this->plan_id,
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'status' => $this->status->value,
            'source' => $this->source,
            'notes' => $this->notes,
            'converted_company_id' => $this->converted_company_id,
            'converted_company' => new CompanyResource($this->whenLoaded('convertedCompany')),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'activities' => ApplicationActivityResource::collection($this->whenLoaded('activities')),
        ];
    }
}
