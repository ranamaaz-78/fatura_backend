<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An invoice from YK Digital Solutions to a company, for a subscription term it bought or renewed. */
class BillingInvoice extends Model
{
    public const KIND_NEW = 'new';

    public const KIND_RENEWAL = 'renewal';

    public const STATUS_PAID = 'paid';

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'number',
        'company_id',
        'subscription_id',
        'payment_id',
        'kind',
        'locale',
        'issued_at',
        'currency',
        'periods',
        'unit_price',
        'total',
        'status',
        'data',
        'sent_at',
        'send_error',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'data' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
