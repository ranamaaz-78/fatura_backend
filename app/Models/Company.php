<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Support\Phone;
use App\Support\Locales;
use App\Support\Text;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory;

    public const CURRENCIES = [
        'EUR', 'USD', 'GBP', 'PKR', 'AED', 'SAR', 'INR', 'MAD', 'CHF', 'CAD', 'AUD',
        'MXN', 'BRL', 'TRY', 'EGP', 'QAR', 'KWD', 'OMR', 'BHD', 'BDT', 'NGN',
    ];

    protected $fillable = [
        'name',
        'slug',
        'email',
        'tax_id',
        'phone',
        'whatsapp',
        'address',
        'city',
        'postal_code',
        'country',
        'currency',
        'locale',
        'logo_path',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
        ];
    }

    /** What a business must give before it can issue documents; the logo is checked as well. */
    public const PROFILE_FIELDS = ['name', 'email', 'tax_id', 'phone', 'whatsapp', 'address', 'city', 'postal_code', 'country', 'currency'];

    /** @return list<string> the profile fields still empty, with "logo" when none is uploaded */
    public function missingProfileFields(): array
    {
        $missing = array_values(array_filter(
            self::PROFILE_FIELDS,
            fn (string $field) => blank($this->getAttribute($field)),
        ));

        if (blank($this->logo_path)) {
            $missing[] = 'logo';
        }

        return $missing;
    }

    public function isProfileComplete(): bool
    {
        return $this->missingProfileFields() === [];
    }

    // Names and places are stored in Proper Case, however they were typed.
    protected function name(): Attribute
    {
        return Attribute::set(fn (?string $value) => Text::proper($value));
    }

    protected function address(): Attribute
    {
        return Attribute::set(fn (?string $value) => Text::proper($value));
    }

    protected function city(): Attribute
    {
        return Attribute::set(fn (?string $value) => Text::proper($value));
    }

    protected function country(): Attribute
    {
        return Attribute::set(fn (?string $value) => Text::proper($value));
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    /** NIF, NIE or CIF: capitals, no spaces or dashes, so the same number is always written the same way. */
    protected function taxId(): Attribute
    {
        return Attribute::set(fn (?string $value) => blank($value) ? null : mb_strtoupper((string) preg_replace('/[\s\-.]+/', '', $value)));
    }

    protected function postalCode(): Attribute
    {
        return Attribute::set(fn (?string $value) => blank($value) ? null : mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $value))));
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::e164($value));
    }

    protected function whatsapp(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::e164($value));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', UserRole::BusinessAdmin->value)->oldest('id');
    }

    /**
     * Newest subscription that has not been cancelled and has not lapsed.
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', SubscriptionStatus::Active->value)
            ->latest('ends_at');
    }

    public function latestSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->status === CompanyStatus::Active;
    }

    protected static function booted(): void
    {
        static::created(function (Company $company) {
            TaxRate::seedDefaults($company->id);
            RecargoRate::seedDefaults($company->id);
            $locale = Locales::normalize($company->locale) ?? Locales::DEFAULT;
            CompanyPaymentMethod::seedDefaults($company->id, $locale);
            PrintTemplate::seedDefaults($company->id, $locale);
        });
    }
}
