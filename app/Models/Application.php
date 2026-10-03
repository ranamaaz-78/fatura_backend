<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name',
        'contact_name',
        'email',
        'phone',
        'whatsapp',
        'city',
        'country',
        'locale',
        'business_type',
        'team_size',
        'message',
        'plan_id',
        'status',
        'source',
        'ip_address',
        'user_agent',
        'notes',
        'converted_company_id',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'converted_at' => 'datetime',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::e164($value));
    }

    protected function whatsapp(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::e164($value));
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function convertedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'converted_company_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ApplicationActivity::class)->latest('id');
    }

    public function isConverted(): bool
    {
        return $this->converted_company_id !== null;
    }

    /**
     * Number used for wa.me links; falls back to the contact phone.
     */
    public function whatsappNumber(): ?string
    {
        return $this->whatsapp ?: $this->phone;
    }
}
