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

    // abono is closed to new documents. Rows issued before proforma existed still load.
    public const TYPES = ['factura', 'albaran', 'quotation', 'proforma', 'abono'];

    public const ISSUABLE = ['factura', 'albaran', 'quotation', 'proforma'];

    public const PAYMENTS = ['pending', 'partial', 'paid'];

    public const MARKABLE_PAYMENTS = ['pending', 'paid'];

    protected $fillable = [
        'company_id',
        'customer_id',
        'created_by',
        'type',
        'number',
        'issued_at',
        'payment_status',
        'payment_method_id',
        'voided_at',
        'void_reason',
        'voided_by',
        'converted_to_id',
        'converted_at',
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
        return $this->hasMany(SalesDocumentLine::class)->orderBy('position');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SalesDocumentSettlement::class)->orderBy('id');
    }

    public function convertedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_to_id');
    }

    /** A quotation promises nothing, so it never moves stock. An abono puts the goods back. */
    public static function stockDirectionFor(string $type): int
    {
        return match ($type) {
            'quotation' => 0,
            'abono' => 1,
            default => -1,
        };
    }

    /** An albarán and a proforma are issued without IVA. */
    public static function carriesTax(string $type): bool
    {
        return $type !== 'albaran' && $type !== 'proforma';
    }

    /** A proforma lists the agreed price only, with nothing taken off it. */
    public static function carriesDiscount(string $type): bool
    {
        return $type !== 'proforma';
    }

    /** An invoice or delivery note can be marked paid. A quote or proforma cannot. */
    public static function settlesPayment(string $type): bool
    {
        return $type === 'factura' || $type === 'albaran' || $type === 'abono';
    }

    /** A proforma is settled line by line, not by marking the whole document paid. */
    public static function settlesLines(string $type): bool
    {
        return $type === 'proforma';
    }

    public function settledCents(): int
    {
        if (array_key_exists('settled_cents', $this->attributes)) {
            return (int) $this->attributes['settled_cents'];
        }

        if ($this->relationLoaded('settlements')) {
            return (int) $this->settlements->sum('total_cents');
        }

        return (int) $this->settlements()->sum('total_cents');
    }

    public function canSettle(): bool
    {
        return self::settlesLines($this->type) && $this->payment_status !== 'paid';
    }

    public function stockDirection(): int
    {
        return self::stockDirectionFor($this->type);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** A paid invoice or delivery note can be voided. Pending ones stay pending until paid. */
    public static function canBeVoided(string $type): bool
    {
        return $type === 'factura' || $type === 'albaran';
    }

    public function canVoid(): bool
    {
        return self::canBeVoided($this->type)
            && $this->payment_status === 'paid'
            && ! $this->isVoided();
    }

    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    public function canEdit(): bool
    {
        return $this->type === 'quotation' && ! $this->isConverted();
    }

    public function canConvert(): bool
    {
        return $this->canEdit();
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'converted_at' => 'datetime',
            'base_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
