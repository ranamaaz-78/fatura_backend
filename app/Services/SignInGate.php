<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Whether this person may be given a session at all, whatever way they arrive: the sign-in form or a
 * set-password link. Kept in one place so the two can never disagree (a link once handed out tokens the sign-in
 * form would have refused).
 */
class SignInGate
{
    /** @return array{message: string, code: string, status: int}|null  Null when they may come in. */
    public function refusal(User $user): ?array
    {
        if ($user->status === UserStatus::Disabled) {
            return ['message' => __('This user account has been disabled.'), 'code' => 'USER_DISABLED', 'status' => 403];
        }

        $company = $user->company;

        if ($company !== null && $company->status === CompanyStatus::Suspended) {
            return ['message' => __('This company account has been suspended.'), 'code' => 'COMPANY_SUSPENDED', 'status' => 403];
        }

        // The owner can still sign in to see the Subscription page and renew; the team waits until it is active.
        if ($company !== null && $user->role === UserRole::Staff) {
            $subscription = $company->activeSubscription;

            if ($subscription === null || ! $subscription->isUsable((int) config('fatura.subscriptions.grace_days'))) {
                return ['message' => __('Your company subscription has expired. Please contact your administrator.'), 'code' => 'SUBSCRIPTION_EXPIRED', 'status' => 403];
            }
        }

        return null;
    }
}
