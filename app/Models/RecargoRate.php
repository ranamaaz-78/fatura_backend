<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Recargo de equivalencia: the surcharge some retailers pay on top of IVA. */
class RecargoRate extends Model
{
    use BelongsToCompany;

    /** The legal rates that go with the three IVA rates. A new company starts with these. */
    public const DEFAULTS = [
        ['name' => 'General', 'rate' => 5.2],
        ['name' => 'Reducido', 'rate' => 1.4],
        ['name' => 'Superreducido', 'rate' => 0.5],
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
}
