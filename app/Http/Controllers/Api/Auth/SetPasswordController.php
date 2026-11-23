<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\SignInGate;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class SetPasswordController extends Controller
{
    use ApiResponse;

    public function store(Request $request, SignInGate $gate): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8), 'max:72'],
        ]);

        $broker = Password::broker('invites');
        $user = $broker->getUser(['email' => $data['email']]);

        // The link is checked first, so nobody without one can learn whether an account is disabled or suspended.
        if (! $user instanceof User || ! $broker->tokenExists($user, $data['token'])) {
            return $this->invalidLink();
        }

        // A link never gets round what the sign-in form refuses: a disabled member, a suspended company, a team
        // whose subscription ended. The link is left unused, so it still works if the account is opened again.
        if ($refusal = $gate->refusal($user)) {
            return response()->json([
                'success' => false,
                'message' => $refusal['message'],
                'data' => [],
                'code' => $refusal['code'],
            ], $refusal['status']);
        }

        /** @var User|null $resolved */
        $resolved = null;

        $status = $broker->reset($data, function (User $user, string $password) use (&$resolved) {
            // Only the password changes. The account status is the owner's or the platform's to set, never the link's.
            $user->forceFill([
                'password' => $password,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));

            $resolved = $user;
        });

        if ($status !== Password::PASSWORD_RESET || $resolved === null) {
            return $this->invalidLink();
        }

        $resolved->forceFill(['last_login_at' => now()])->save();

        return $this->success([
            'token' => $resolved->createToken('api')->plainTextToken,
            'user' => new UserResource($resolved),
            'role' => $resolved->role->value,
        ], __('Your password is set. Welcome aboard.'));
    }

    private function invalidLink(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('This link is invalid or has expired.'),
            'data' => [],
            'code' => 'INVITE_INVALID',
            'support' => [
                'email' => config('fatura.support.email'),
                'whatsapp' => config('fatura.support.whatsapp'),
            ],
        ], 422);
    }
}
