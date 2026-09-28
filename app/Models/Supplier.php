<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use BelongsToCompany, HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'company_name',
        'phone',
        'nif',
        'nie',
        'is_active',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
