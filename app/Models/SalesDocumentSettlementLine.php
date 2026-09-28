<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDocumentSettlementLine extends Model
{
    protected $fillable = [
        'sales_document_settlement_id',
        'sales_document_line_id',
        'quantity',
        'unit_price',
        'total_cents',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentSettlement::class, 'sales_document_settlement_id');
    }

    public function documentLine(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentLine::class, 'sales_document_line_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
