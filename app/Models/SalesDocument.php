<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Text;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SalesDocument extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    // abono is closed to new documents. Rows issued before proforma existed still load.
    // nota_entrega (delivery receipt) is its own record of goods handed over: prices and a total only (no IVA, recargo
    // or discount), stock out, no client, no payment, never converted. It has its own screen, apart from the POS.
    public const TYPES = ['factura', 'albaran', 'quotation', 'proforma', 'nota_entrega', 'abono'];

    public const ISSUABLE = ['factura', 'albaran', 'quotation', 'proforma', 'nota_entrega'];

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
        'draft_at',
        'expires_at',
        'payment_status',
        'payment_method_id',
        'voided_at',
        'void_reason',
        'voided_by',
        'deleted_by',
        'deleted_source_id',
        'converted_to_id',
        'converted_at',
        'client_code',
        'client_name',
        'client_company',
        'client_phone',
        'client_nif',
        'client_nie',
        'client_address',
        'client_email',
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

    /** Who deleted it (while it is in the Deleted list). */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
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
            'nota_entrega' => __('Delivery receipt'),
            'abono' => __('Credit note'),
            default => ucfirst($type),
        };
    }

    /** A delivery receipt names no client: the sheet only says "Client". Every other kind is issued to someone. */
    public static function hasClient(string $type): bool
    {
        return $type !== 'nota_entrega';
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

    /** An albarán, a proforma and a delivery receipt are issued without IVA. */
    public static function carriesTax(string $type): bool
    {
        return ! in_array($type, ['albaran', 'proforma', 'nota_entrega'], true);
    }

    /** Recargo de equivalencia can be added to an invoice or a quotation, and to nothing else. */
    public static function carriesRecargo(string $type): bool
    {
        return $type === 'factura' || $type === 'quotation';
    }

    /** A proforma and a delivery receipt list the price only, with nothing taken off it (no line or bill discount). */
    public static function carriesDiscount(string $type): bool
    {
        return $type !== 'proforma' && $type !== 'nota_entrega';
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

    /**
     * Why this document cannot be deleted, or null when it can. A document is deleted only while nothing has happened
     * to it that must stay on the books: never once it is paid (a paid invoice or delivery note is voided instead),
     * never a voided one, and never one another document still stands on.
     */
    public function deleteBlockedReason(): ?string
    {
        if ($this->trashed()) {
            return __('This document was already deleted.');
        }

        if ($this->type === 'abono') {
            return __('A credit note cannot be deleted.');
        }

        if ($this->isVoided()) {
            return __('A voided document is kept as a record and cannot be deleted.');
        }

        if ($this->type === 'factura' && $this->from_settlement_id !== null) {
            return __('An invoice made from a proforma payment cannot be deleted.');
        }

        if ($this->type === 'proforma') {
            // A proforma is settled piece by piece: any payment or return is already a record.
            if ($this->payment_status !== 'pending' || (int) $this->returned_cents > 0 || ($this->relationLoaded('settlements') ? $this->settlements->isNotEmpty() : $this->settlements()->exists())) {
                return __('A proforma with payments or returns cannot be deleted.');
            }
        } elseif ($this->payment_status !== 'pending') {
            return $this->type === 'albaran'
                ? __('A paid delivery note cannot be deleted. Void it instead.')
                : __('A paid invoice cannot be deleted. Void it instead.');
        }

        if ($this->type === 'albaran' && ! $this->canInvoice()) {
            return __('This delivery note already has an invoice. Delete the invoice first.');
        }

        if ($this->type === 'quotation' && $this->isConverted() && $this->convertedTo()->exists()) {
            return __('A document was made from this quotation. Delete that document first.');
        }

        return null;
    }

    public function canDelete(): bool
    {
        return $this->deleteBlockedReason() === null;
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

    /** An open quotation: not converted, not past its week. */
    public function canEditQuotation(): bool
    {
        return $this->type === 'quotation' && ! $this->isConverted() && ! $this->isExpired();
    }

    /**
     * A delivery note can be corrected while it is unpaid, as long as no invoice stands on it (an invoice that was
     * voided no longer does). Once it is paid, partly or fully, it can only be voided.
     */
    public function canEditDeliveryNote(): bool
    {
        return $this->type === 'albaran' && $this->payment_status === 'pending' && $this->canInvoice();
    }

    public function canEdit(): bool
    {
        return $this->canEditQuotation() || $this->canEditDeliveryNote() || $this->canEditDraft();
    }

    /**
     * A draft invoice: it already holds its number and date (the next invoice takes the number after it), but it has
     * moved no stock and is no sale yet. It is edited freely, then issued (the stock goes out then), or deleted, which
     * keeps it as a voided invoice so its number never leaves a gap.
     */
    public function isDraft(): bool
    {
        return $this->draft_at !== null;
    }

    /** A live draft can still be changed and issued. Once deleted (voided) it is only a record. */
    public function canEditDraft(): bool
    {
        return $this->type === 'factura' && $this->isDraft() && ! $this->isVoided();
    }

    public function canIssueDraft(): bool
    {
        return $this->canEditDraft();
    }

    /** Only issued documents are sales: drafts are left out of every total, list stat and report. */
    public function scopeIssued(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNull($query->getModel()->getTable().'.draft_at');
    }

    public function canConvert(): bool
    {
        return $this->canEditQuotation();
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'draft_at' => 'datetime',
            'expires_at' => 'datetime',
            'voided_at' => 'datetime',
            'deleted_at' => 'datetime',
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
