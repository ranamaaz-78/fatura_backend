<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Enums\ApplicationStatus;
use App\Enums\CompanyStatus;
use App\Enums\PaymentStatus;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationActivity;
use App\Models\Company;
use App\Support\Locales;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConvertApplicationService
{
    public function __construct(
        private readonly AccountProvisionNotifier $notifier,
    ) {}

    /**
     * Turn a lead into a company + owner + paid subscription.
     *
     * @param  array<string, mixed>  $input
     * @return array{company: Company, owner: User, subscription: Subscription, payment: Payment|null, whatsapp_url: string|null}
     */
    public function handle(Application $application, array $input, ?User $actor = null): array
    {
        $plan = Plan::findOrFail($input['plan_id']);

        $result = DB::transaction(function () use ($application, $input, $plan, $actor) {
            $company = Company::create([
                'name' => $input['company_name'],
                'slug' => $this->uniqueSlug($input['company_name']),
                'email' => $input['company_email'],
                'phone' => $input['company_phone'] ?? $application->phone,
                'whatsapp' => $input['company_whatsapp'] ?? $application->whatsapp,
                'address' => $input['address'] ?? null,
                'city' => $input['city'] ?? $application->city,
                'country' => $input['country'] ?? $application->country,
                'currency' => $input['currency'] ?? $plan->currency,
                'locale' => Locales::normalize($input['locale'] ?? null) ?? Locales::normalize($application->locale) ?? Locales::DEFAULT,
                'status' => CompanyStatus::Active,
            ]);

            $owner = User::create([
                'name' => $input['owner_name'],
                'email' => $input['owner_email'],
                'password' => null,
                'phone' => $input['owner_phone'] ?? $application->phone,
                'whatsapp' => $input['owner_whatsapp'] ?? $application->whatsapp,
                'role' => UserRole::BusinessAdmin,
                'company_id' => $company->id,
                'status' => UserStatus::Active,
            ]);

            $startsAt = isset($input['starts_at'])
                ? Carbon::parse($input['starts_at'])->startOfDay()
                : now();

            $periods = (int) ($input['periods'] ?? 1);
            $interval = $plan->interval instanceof PlanInterval ? $plan->interval : PlanInterval::from($plan->interval);

            $subscription = Subscription::create([
                'company_id' => $company->id,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_price' => $plan->price,
                'plan_currency' => $plan->currency,
                'plan_interval' => $interval,
                'plan_features' => $plan->features,
                'status' => SubscriptionStatus::Active,
                'starts_at' => $startsAt,
                'ends_at' => $interval->advance($startsAt, $periods),
                'notes' => $input['subscription_notes'] ?? null,
            ]);

            $payment = null;

            if (! empty($input['payment_method_id'])) {
                $payment = Payment::create([
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'payment_method_id' => $input['payment_method_id'],
                    'amount' => $input['amount'] ?? ((float) $plan->price * $periods),
                    'currency' => $plan->currency,
                    'status' => PaymentStatus::Paid,
                    'reference' => $input['payment_reference'] ?? null,
                    'paid_at' => isset($input['paid_at']) ? Carbon::parse($input['paid_at']) : now(),
                    'notes' => $input['payment_notes'] ?? null,
                    'recorded_by' => $actor?->id,
                ]);
            }

            $application->forceFill([
                'status' => ApplicationStatus::Approved,
                'converted_company_id' => $company->id,
                'converted_at' => now(),
            ])->save();

            ApplicationActivity::create([
                'application_id' => $application->id,
                'user_id' => $actor?->id,
                'type' => ActivityType::Converted,
                'body' => __('Converted to company :company', ['company' => $company->name]),
                'meta' => [
                    'company_id' => $company->id,
                    'subscription_id' => $subscription->id,
                    'plan' => $plan->name,
                ],
            ]);

            return compact('company', 'owner', 'subscription', 'payment');
        });

        // Side effects run after commit so a rollback never leaks a live invite link.
        $delivery = $this->notifier->sendAccountReady(
            $result['owner'],
            $result['company'],
            $result['subscription'],
            $application,
        );

        return [
            ...$result,
            'whatsapp_url' => $delivery->whatsappUrl,
            'email_sent' => $delivery->emailSent,
            'email_error' => $delivery->emailError,
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $i = 2;

        while (Company::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
