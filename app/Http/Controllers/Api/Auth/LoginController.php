<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SignInGate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    use ApiResponse;

    public function store(Request $request, SignInGate $gate): JsonResponse
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

        // Upgrade the stored hash when the hashing settings changed (higher cost, another algorithm).
        // The 'hashed' cast hashes the plain password again with the current settings.
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $data['password']])->save();
        }

        // Disabled, suspended, or a team member whose company subscription ended: the same rules as a set-password link.
        if ($refusal = $gate->refusal($user)) {
            return $this->coded($refusal['message'], $refusal['code'], $refusal['status']);
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
