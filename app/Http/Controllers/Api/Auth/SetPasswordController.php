<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        /** @var User|null $resolved */
        $resolved = null;

        $status = Password::broker('invites')->reset($data, function (User $user, string $password) use (&$resolved) {
            $user->forceFill([
                'password' => $password,
                'status' => UserStatus::Active,
                'email_verified_at' => $user->email_verified_at ?? now(),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));

            $resolved = $user;
        });

        if ($status !== Password::PASSWORD_RESET || $resolved === null) {
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

        $resolved->forceFill(['last_login_at' => now()])->save();

        return $this->success([
            'token' => $resolved->createToken('api')->plainTextToken,
            'user' => new UserResource($resolved),
            'role' => $resolved->role->value,
        ], __('Your password is set. Welcome aboard.'));
    }
}
