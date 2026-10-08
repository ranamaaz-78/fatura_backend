<?php

namespace App\Http\Resources;

use App\Support\Text;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => Text::proper($this->name),
            'slug' => $this->slug,
            'email' => $this->email,
            'tax_id' => $this->tax_id,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'address' => Text::proper($this->address),
            'city' => Text::proper($this->city),
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'document_locale' => $this->document_locale,
            'logo_url' => $this->logo_path ? '/app/company/logo' : null,
            'profile_complete' => $this->isProfileComplete(),
            'missing_fields' => $this->missingProfileFields(),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'users_count' => $this->whenCounted('users'),
            'owner' => new UserResource($this->whenLoaded('owner')),
            'active_subscription' => new SubscriptionResource($this->whenLoaded('activeSubscription')),
            'latest_subscription' => new SubscriptionResource($this->whenLoaded('latestSubscription')),
            'subscriptions' => SubscriptionResource::collection($this->whenLoaded('subscriptions')),
            'subscription_state' => $this->subscriptionState(),
        ];
    }

    /** none, active, expiring (ends within a week) or expired. Needs the subscription relations loaded. */
    private function subscriptionState(): ?string
    {
        if (! $this->relationLoaded('activeSubscription') || ! $this->relationLoaded('latestSubscription')) {
            return null;
        }

        $active = $this->activeSubscription;

        if ($active !== null && $active->isUsable((int) config('fatura.subscriptions.grace_days'))) {
            return $active->daysLeft() <= 7 ? 'expiring' : 'active';
        }

        return $this->latestSubscription === null ? 'none' : 'expired';
    }
}
