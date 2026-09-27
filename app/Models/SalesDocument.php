<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesDocument extends Model
{
    use BelongsToCompany, HasFactory;

    public const TYPES = ['factura', 'albaran', 'abono'];

    public const PAYMENTS = ['pending', 'paid'];

    protected $fillable = [
        'company_id',
        'customer_id',
        'created_by',
        'type',
        'number',
        'issued_at',
        'payment_status',
        'client_code',
        'client_name',
        'client_company',
        'client_phone',
        'client_nif',
        'client_nie',
        'notes',
        'base_cents',
        'tax_cents',
        'total_cents',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentLine::class)->orderBy('position');
    }

    /** Factura and albaran leave the warehouse. An abono puts the goods back. */
    public function stockDirection(): int
    {
        return $this->type === 'abono' ? 1 : -1;
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'base_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
