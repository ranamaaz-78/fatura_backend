<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return $this->coded(
                __('Too many login attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($throttleKey)]),
                'TOO_MANY_ATTEMPTS',
                429,
            );
        }

        $user = User::where('email', $data['email'])->first();

        // Say which part is wrong, so the person knows whether to fix the email or the password.
        if ($user === null) {
            RateLimiter::hit($throttleKey);

            return $this->coded(__('No account is registered with this email address.'), 'ACCOUNT_NOT_FOUND', 422);
        }

        if ($user->password === null) {
            RateLimiter::hit($throttleKey);

            return $this->coded(__('This account has no password yet. Use the link in your invitation email, or choose "Forgot password".'), 'PASSWORD_NOT_SET', 422);
        }

        if (! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey);

            return $this->coded(__('The password is incorrect.'), 'WRONG_PASSWORD', 422);
        }

        if ($user->status === UserStatus::Disabled) {
            return $this->coded(__('This user account has been disabled.'), 'USER_DISABLED', 403);
        }

        $company = $user->company;

        if ($company !== null && $company->status === CompanyStatus::Suspended) {
            return $this->coded(__('This company account has been suspended.'), 'COMPANY_SUSPENDED', 403);
        }

        // The owner can still sign in to see the Subscription page and renew; the team waits until it is active.
        if ($company !== null && $user->role === UserRole::Staff) {
            $subscription = $company->activeSubscription;

            if ($subscription === null || ! $subscription->isUsable((int) config('fatura.subscriptions.grace_days'))) {
                return $this->coded(__('Your company subscription has expired. Please contact your administrator.'), 'SUBSCRIPTION_EXPIRED', 403);
            }
        }

        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('api')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => new UserResource($user),
            'role' => $user->role->value,
        ], __('Signed in.'));
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return $this->success([], __('Signed out.'));
    }

    private function coded(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [],
            'code' => $code,
            // Shown under the box it is about.
            'errors' => match ($code) {
                'ACCOUNT_NOT_FOUND' => ['email' => [$message]],
                'WRONG_PASSWORD', 'PASSWORD_NOT_SET' => ['password' => [$message]],
                default => [],
            },
        ], $status);
    }
}
