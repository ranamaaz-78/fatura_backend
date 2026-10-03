<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CompanyPaymentMethod extends Model
{
    use BelongsToCompany, HasFactory;

    /** The methods a new company starts with. */
    public const DEFAULTS = [
        ['name' => 'Cash', 'sort_order' => 0],
        ['name' => 'Card', 'sort_order' => 1],
        ['name' => 'Bank transfer', 'sort_order' => 2],
    ];

    protected $fillable = [
        'company_id',
        'name',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SalesDocument::class, 'payment_method_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SalesDocumentSettlement::class, 'payment_method_id');
    }

    public static function seedDefaults(int $companyId, ?string $locale = null): void
    {
        foreach (self::DEFAULTS as $row) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'name' => __($row['name'], [], $locale)],
                ['is_active' => true, 'sort_order' => $row['sort_order']],
            );
        }
    }

    public static function requireActive(int $companyId, mixed $id): int
    {
        if ($id === null || $id === '') {
            throw ValidationException::withMessages([
                'payment_method_id' => __('Pick how this was paid.'),
            ]);
        }

        $method = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereKey($id)
            ->first();

        if ($method === null || ! $method->is_active) {
            throw ValidationException::withMessages([
                'payment_method_id' => __('That payment method is not available.'),
            ]);
        }

        return (int) $method->id;
    }
}
