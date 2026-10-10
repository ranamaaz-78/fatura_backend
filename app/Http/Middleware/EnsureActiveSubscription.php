<?php

namespace App\Http\Middleware;

use App\Enums\CompanyStatus;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $company = $user?->company;

        if ($user !== null && $user->status === UserStatus::Disabled) {
            $user->currentAccessToken()?->delete();

            return $this->blocked(__('This user account has been disabled.'), 'USER_DISABLED', 403);
        }

        if ($company === null) {
            return $this->blocked(__('Your account is not linked to a company yet.'), 'NO_COMPANY');
        }

        if ($company->status === CompanyStatus::Suspended) {
            return $this->blocked(__('This account has been suspended.'), 'COMPANY_SUSPENDED', 403);
        }

        $subscription = $company->activeSubscription;

        if ($subscription === null || ! $subscription->isUsable((int) config('fatura.subscriptions.grace_days'))) {
            return $this->blocked(__('Your subscription has expired.'), 'SUBSCRIPTION_EXPIRED');
        }

        return $next($request);
    }

    private function blocked(string $message, string $code, int $status = 402): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [],
            'code' => $code,
            'support' => [
                'email' => config('fatura.support.email'),
                'whatsapp' => config('fatura.support.whatsapp'),
            ],
        ], $status);
    }
}
