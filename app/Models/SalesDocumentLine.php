<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDocumentLine extends Model
{
    protected $fillable = [
        'sales_document_id',
        'product_id',
        'position',
        'sr_number',
        'article',
        'description',
        'quantity',
        'unit_price',
        'discount_percent',
        'iva_percent',
        'base_cents',
        'tax_cents',
        'total_cents',
        'bill_discount_cents',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function settlementLines(): HasMany
    {
        return $this->hasMany(SalesDocumentSettlementLine::class);
    }

    public function settledQuantity(): int
    {
        if ($this->relationLoaded('settlementLines')) {
            return (int) $this->settlementLines->sum('quantity');
        }

        return (int) $this->settlementLines()->sum('quantity');
    }

    public function remainingQuantity(): int
    {
        return max(0, (int) $this->quantity - $this->settledQuantity());
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'discount_percent' => 'float',
            'iva_percent' => 'float',
            'base_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'bill_discount_cents' => 'integer',
        ];
    }
}
