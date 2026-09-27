<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    use BelongsToCompany;

    /** The rates a new company starts with. */
    public const DEFAULTS = [
        ['name' => 'General', 'rate' => 21],
        ['name' => 'Reducido', 'rate' => 10],
        ['name' => 'Superreducido', 'rate' => 4],
        ['name' => 'Exento', 'rate' => 0],
    ];

    protected $fillable = [
        'company_id',
        'name',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
        ];
    }

    public static function seedDefaults(int $companyId): void
    {
        foreach (self::DEFAULTS as $row) {
            static::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $companyId, 'rate' => $row['rate']],
                ['name' => $row['name']],
            );
        }
    }

    public static function allows(int $companyId, float $rate): bool
    {
        $needle = round($rate, 2);

        return static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('rate')
            ->contains(fn ($stored) => round((float) $stored, 2) === $needle);
    }
}
