<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Pricing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'category_id',
        'sr_number',
        'article',
        'description',
        'brand',
        'image_code',
        'barcode',
        'barcode_generated',
        'quantity',
        'minimum_stock',
        'buying_price',
        'selling_price',
        'margin_percent',
        'iva_percent',
    ];

    protected function casts(): array
    {
        return [
            'barcode_generated' => 'boolean',
            'quantity' => 'integer',
            'minimum_stock' => 'integer',
            'buying_price' => 'integer',
            'selling_price' => 'integer',
            'margin_percent' => 'decimal:2',
            'iva_percent' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** At or below the reorder point, and not already out of stock. */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'minimum_stock');
    }

    public function scopeOutOfStock(Builder $query): Builder
    {
        return $query->where('quantity', '<=', 0);
    }

    public function expectedSellingPrice(): int
    {
        return Pricing::sellingPrice($this->buying_price, (float) $this->margin_percent, (float) $this->iva_percent);
    }
}
