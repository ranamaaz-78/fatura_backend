<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Text;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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
        'verify_code',
        'issued_at',
        'expires_at',
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
        'client_address',
        'notes',
        'base_cents',
        'tax_cents',
        'discount_type',
        'discount_value',
        'discount_cents',
        'recargo_percent',
        'recargo_cents',
        'total_cents',
        'returned_cents',
        'from_settlement_id',
        'from_document_id',
    ];

    protected static function booted(): void
    {
        // Every invoice gets the secret its QR code carries, whichever way it came to exist.
        static::saving(function (SalesDocument $document) {
            if ($document->type === 'factura' && blank($document->verify_code)) {
                $document->verify_code = Str::lower(Str::random(24));
            }
        });
    }

    // Names and places print in Proper Case ("taller de marta s.l." becomes "Taller de Marta S.L."), as on the company.
    protected function clientName(): Attribute
    {
        return Attribute::set(fn (?string $value) => Text::proper($value));
    }

    protected function clientCompany(): Attribute
    {
        return Attribute::set(fn (?string $value) => Text::proper($value));
    }

    protected function clientAddress(): Attribute
    {
        return Attribute::set(fn (?string $value) => Text::proper($value));
    }

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

    /** The proforma payment this invoice was made from, if any. */
    public function fromSettlement(): BelongsTo
    {
        return $this->belongsTo(SalesDocumentSettlement::class, 'from_settlement_id');
    }

    /** The delivery note this invoice was made from, if any. */
    public function fromDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'from_document_id');
    }

    /** An invoice made from a proforma payment or a delivery note is paper only: it moves no stock and adds no sale. */
    public function isDerived(): bool
    {
        return $this->from_settlement_id !== null || $this->from_document_id !== null;
    }

    /**
     * A delivery note can be turned into an invoice once. If that invoice was voided, it can be done again.
     * The stock already left with the delivery note, so the invoice moves none.
     */
    public function canInvoice(): bool
    {
        if ($this->type !== 'albaran' || $this->isVoided()) {
            return false;
        }

        if (! $this->isConverted()) {
            return true;
        }

        $invoice = $this->relationLoaded('convertedTo') ? $this->convertedTo : $this->convertedTo()->first();

        return $invoice === null || $invoice->isVoided();
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SalesDocumentReturn::class)->orderBy('id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SalesDocumentSettlement::class)->orderBy('id');
    }

    public function convertedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_to_id');
    }

    /** What a document of this type is called to the customer. */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'factura' => __('Invoice'),
            'albaran' => __('Delivery note'),
            'quotation' => __('Quotation'),
            'proforma' => __('Proforma'),
            'abono' => __('Credit note'),
            default => ucfirst($type),
        };
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

    /** Recargo de equivalencia can be added to an invoice or a quotation, and to nothing else. */
    public static function carriesRecargo(string $type): bool
    {
        return $type === 'factura' || $type === 'quotation';
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

    /** Pieces of a proforma can come back while some are still unpaid and not yet returned. */
    public function canReturn(): bool
    {
        return self::settlesLines($this->type) && $this->payment_status !== 'paid' && ! $this->isVoided();
    }

    /** Every piece came back and nothing was paid: the proforma ended without a sale. */
    public function isFullyReturned(): bool
    {
        return $this->type === 'proforma'
            && (int) $this->returned_cents > 0
            && (int) $this->returned_cents >= (int) $this->total_cents
            && $this->settledCents() === 0;
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

    /** The last moment a quotation stays open: the end of the day, valid_days after the date written on it. */
    public static function expiryFor(\DateTimeInterface $issuedAt): \Illuminate\Support\Carbon
    {
        // The day is the business's day: a quotation dated late evening in Karachi still has its full week.
        return \Illuminate\Support\Carbon::instance($issuedAt)
            ->setTimezone(config('fatura.timezone'))
            ->addDays((int) config('fatura.quotations.valid_days', 7))
            ->endOfDay()
            ->setTimezone(config('app.timezone'));
    }

    /** An open quotation that has run past its week. Once converted it is settled, so it never expires. */
    public function isExpired(): bool
    {
        return $this->type === 'quotation'
            && ! $this->isConverted()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function canEdit(): bool
    {
        return $this->type === 'quotation' && ! $this->isConverted() && ! $this->isExpired();
    }

    public function canConvert(): bool
    {
        return $this->canEdit();
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'voided_at' => 'datetime',
            'converted_at' => 'datetime',
            'base_cents' => 'integer',
            'tax_cents' => 'integer',
            'discount_value' => 'float',
            'discount_cents' => 'integer',
            'recargo_percent' => 'float',
            'recargo_cents' => 'integer',
            'total_cents' => 'integer',
            'returned_cents' => 'integer',
        ];
    }
}
