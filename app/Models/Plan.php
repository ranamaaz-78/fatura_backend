<?php

namespace App\Models;

use App\Enums\PlanInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'name_es',
        'slug',
        'description',
        'description_es',
        'price',
        'currency',
        'interval',
        'features',
        'features_es',
        'max_users',
        'max_invoices',
        'is_featured',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'interval' => PlanInterval::class,
            'features' => 'array',
            'features_es' => 'array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** The plan's text in the current language; English when no Spanish was written. */
    public function localized(string $field): mixed
    {
        if (app()->getLocale() === 'es') {
            $spanish = $this->{$field.'_es'};

            if (filled($spanish)) {
                return $spanish;
            }
        }

        return $this->{$field};
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
