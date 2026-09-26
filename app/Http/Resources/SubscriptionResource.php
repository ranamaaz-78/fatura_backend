<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'plan_id' => $this->plan_id,
            'plan_name' => $this->plan_name,
            'plan_price' => (float) $this->plan_price,
            'plan_currency' => $this->plan_currency,
            'plan_interval' => $this->plan_interval->value,
            'plan_features' => $this->plan_features ?? [],
            'status' => $this->status->value,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'days_left' => $this->daysLeft(),
            'is_usable' => $this->isUsable((int) config('fatura.subscriptions.grace_days')),
            'notes' => $this->notes,
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
