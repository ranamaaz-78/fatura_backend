<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDocumentSettlement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'sales_document_id',
        'payment_method_id',
        'created_by',
        'total_cents',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(CompanyPaymentMethod::class, 'payment_method_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentSettlementLine::class);
    }

    protected function casts(): array
    {
        return [
            'total_cents' => 'integer',
        ];
    }
}
