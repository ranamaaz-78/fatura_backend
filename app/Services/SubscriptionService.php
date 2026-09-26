<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    /**
     * Start a new term for a company. Renewing on the same plan continues from the
     * current end date; switching plans starts today.
     *
     * @param  array<string, mixed>  $input
     */
    public function start(Company $company, array $input, ?User $actor = null): Subscription
    {
        $plan = Plan::findOrFail($input['plan_id']);
        $current = $company->activeSubscription;

        $startsAt = match (true) {
            isset($input['starts_at']) => Carbon::parse($input['starts_at']),
            $current !== null && $current->plan_id === $plan->id && $current->ends_at->isFuture() => $current->ends_at->copy(),
            default => now(),
        };

        $periods = (int) ($input['periods'] ?? 1);
        $interval = $plan->interval instanceof PlanInterval ? $plan->interval : PlanInterval::from($plan->interval);

        return DB::transaction(function () use ($company, $plan, $startsAt, $periods, $interval, $input, $actor, $current) {
            $current?->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

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

            if (! empty($input['payment_method_id'])) {
                Payment::create([
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

            return $subscription;
        });
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        return $subscription->fresh();
    }
}
