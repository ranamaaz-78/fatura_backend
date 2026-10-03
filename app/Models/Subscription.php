<?php

namespace App\Models;

use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'plan_id',
        'plan_name',
        'plan_price',
        'plan_currency',
        'plan_interval',
        'plan_features',
        'plan_translations',
        'status',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'plan_price' => 'decimal:2',
            'plan_interval' => PlanInterval::class,
            'plan_features' => 'array',
            'plan_translations' => 'array',
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** The plan's Spanish wording as it was when this term began, when the request is in Spanish and there is one. */
    public function translated(string $field): mixed
    {
        if (app()->getLocale() !== 'es') {
            return null;
        }

        $value = $this->plan_translations['es'][$field] ?? null;

        return filled($value) ? $value : null;
    }

    public function daysLeft(): int
    {
        return max(0, (int) now()->startOfDay()->diffInDays($this->ends_at->startOfDay(), false));
    }

    public function isUsable(int $graceDays = 0): bool
    {
        return $this->status === SubscriptionStatus::Active
            && $this->ends_at->copy()->addDays($graceDays)->isFuture();
    }
}
