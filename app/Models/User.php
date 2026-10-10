<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Mail\PasswordResetMail;
use App\Notifications\InvitePasswordNotification;
use App\Support\Locales;
use App\Support\Permissions;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'whatsapp',
        'role',
        'company_id',
        'status',
        'permissions',
        'team_role',
        'locale',
        'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'permissions' => 'array',
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

    /** What language this person is written to in: their own choice, else their company's. */
    public function preferredLocale(): string
    {
        return Locales::normalize($this->locale) ?? Locales::normalize($this->company?->locale) ?? app()->getLocale();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The company owner: may do everything, and is the only one who can manage the team. */
    public function isOwner(): bool
    {
        return $this->role === UserRole::BusinessAdmin;
    }

    /**
     * Every "area.action" this person may use. The owner has them all; a team member has what the owner
     * ticked for them (a staff row from before permissions existed counts as a Manager).
     *
     * @return list<string>
     */
    public function permissionList(): array
    {
        if ($this->isOwner()) {
            return Permissions::all();
        }

        if ($this->role !== UserRole::Staff) {
            return [];
        }

        return Permissions::normalize($this->permissions ?? Permissions::preset('manager'));
    }

    /** "invoices.create": may this person do it? (Named so it does not clash with Laravel's own can().) */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionList(), true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** The reset email is sent straight away: the person is waiting for it on the forgot-password screen. */
    public function sendPasswordResetNotification($token): void
    {
        $url = rtrim(config('fatura.frontend_url'), '/')
            .'/reset-password?token='.$token
            .'&email='.urlencode($this->getEmailForPasswordReset());

        Mail::to($this->email)->locale($this->preferredLocale())->send(new PasswordResetMail($this, $url));
    }

    public function sendInviteNotification(string $token): void
    {
        $this->notify(new InvitePasswordNotification($token));
    }
}
