<?php

namespace App\Services\Demo;

use App\Enums\CompanyStatus;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;

/**
 * The shared demo account: one company, flagged "is_demo", with one owner user that has no password (nobody can sign
 * in to it from the login form; customers are let in through DemoController, which gives them a short-lived token).
 */
class DemoCompany
{
    public const SLUG = 'demo-shop';

    /** A ".invalid" address can never receive mail, so nothing sent to the demo can reach a real inbox. */
    public const OWNER_EMAIL = 'demo-owner@demo.invalid';

    public function find(): ?Company
    {
        return Company::query()->where('is_demo', true)->first();
    }

    /** The demo company, made on the first call, with a subscription that does not run out. */
    public function ensure(): Company
    {
        $company = $this->find() ?? Company::create([
            'name' => 'Demo Shop',
            'slug' => self::SLUG,
            'email' => 'demo@demo.invalid',
            'tax_id' => 'B00000000',
            'phone' => '+34 900 000 000',
            'whatsapp' => '+34 900 000 000',
            'address' => 'Calle Demo 1',
            'city' => 'Madrid',
            'postal_code' => '28001',
            'country' => 'ES',
            'currency' => 'EUR',
            'locale' => 'en',
            'status' => CompanyStatus::Active,
            'is_demo' => true,
        ]);

        if (! $company->is_demo) {
            $company->forceFill(['is_demo' => true])->save();
        }

        $this->owner($company);
        $this->keepSubscriptionRunning($company);

        return $company;
    }

    public function owner(Company $company): User
    {
        return User::query()->where('company_id', $company->id)->where('role', UserRole::BusinessAdmin)->first()
            ?? User::create([
                'name' => 'Demo Owner',
                'email' => self::OWNER_EMAIL,
                'password' => null,
                'role' => UserRole::BusinessAdmin,
                'company_id' => $company->id,
                'status' => UserStatus::Active,
            ]);
    }

    private function keepSubscriptionRunning(Company $company): void
    {
        $subscription = Subscription::withoutGlobalScopes()->where('company_id', $company->id)->latest('id')->first();

        if ($subscription !== null && $subscription->status === SubscriptionStatus::Active && $subscription->ends_at->gt(now()->addYears(2))) {
            return;
        }

        Subscription::create([
            'company_id' => $company->id,
            'plan_id' => null,
            'plan_name' => 'Demo',
            'plan_price' => 0,
            'plan_currency' => 'EUR',
            'plan_interval' => PlanInterval::Year,
            'plan_features' => [],
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYears(10),
        ]);
    }
}
