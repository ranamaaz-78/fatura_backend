<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    /** The admin always sees the English source text; everyone else sees it in their language. */
    private function shown(Request $request, string $field): mixed
    {
        return $request->is('api/admin/*') ? $this->{$field} : $this->localized($field);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->shown($request, 'name'),
            'name_es' => $this->name_es,
            'slug' => $this->slug,
            'description' => $this->shown($request, 'description'),
            'description_es' => $this->description_es,
            'price' => (float) $this->price,
            'original_price' => $this->original_price !== null ? (float) $this->original_price : null,
            'currency' => $this->currency,
            'interval' => $this->interval->value,
            'features' => $this->shown($request, 'features') ?? [],
            'features_es' => $this->features_es ?? [],
            'max_users' => $this->max_users,
            'max_invoices' => $this->max_invoices,
            'is_featured' => (bool) $this->is_featured,
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
