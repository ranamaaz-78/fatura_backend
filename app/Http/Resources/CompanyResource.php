<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'currency' => $this->currency,
            'logo_url' => $this->logo_path ? '/app/company/logo' : null,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'users_count' => $this->whenCounted('users'),
            'owner' => new UserResource($this->whenLoaded('owner')),
            'active_subscription' => new SubscriptionResource($this->whenLoaded('activeSubscription')),
            'latest_subscription' => new SubscriptionResource($this->whenLoaded('latestSubscription')),
            'subscriptions' => SubscriptionResource::collection($this->whenLoaded('subscriptions')),
        ];
    }
}
