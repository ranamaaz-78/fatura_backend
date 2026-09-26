<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\CompanyStatus;
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

        if ($user === null || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey);

            return $this->coded(__('These credentials do not match our records.'), 'INVALID_CREDENTIALS', 422);
        }

        if ($user->status === UserStatus::Disabled) {
            return $this->coded(__('This user account has been disabled.'), 'USER_DISABLED', 403);
        }

        $company = $user->company;

        if ($company !== null && $company->status === CompanyStatus::Suspended) {
            return $this->coded(__('This company account has been suspended.'), 'COMPANY_SUSPENDED', 403);
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
            'errors' => $code === 'INVALID_CREDENTIALS' ? ['email' => [$message]] : [],
        ], $status);
    }
}
