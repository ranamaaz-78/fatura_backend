<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\NotificationLogger;
use App\Traits\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class PasswordResetController extends Controller
{
    use ApiResponse;

    /**
     * Email a reset link, but only when an account exists for the address. Unknown addresses
     * are told so on purpose: the person asked, and nothing is sent.
     */
    public function forgot(Request $request, NotificationLogger $logger): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if ($user === null) {
            return $this->coded(__('No account is registered with this email address.'), 'ACCOUNT_NOT_FOUND', 404);
        }

        if ($user->status === UserStatus::Disabled) {
            return $this->coded(__('This user account has been disabled. Please contact support.'), 'USER_DISABLED', 403);
        }

        try {
            $status = Password::sendResetLink(['email' => $user->email]);
        } catch (Throwable $e) {
            Log::error('Password reset email failed: '.$e->getMessage());
            $logger->log(NotificationChannel::Email, 'password_reset', $user->email, NotificationStatus::Failed, [
                'user_id' => $user->id,
                'company_id' => $user->company_id,
                'error' => $e->getMessage(),
            ]);

            return $this->coded(__('We could not send the email right now. Please try again in a few minutes.'), 'MAIL_FAILED', 503);
        }

        if ($status === Password::RESET_THROTTLED) {
            return $this->coded(__('A reset link was sent a moment ago. Please wait a minute before asking for another one.'), 'RESET_THROTTLED', 429);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            return $this->coded(__('We could not send the reset link. Please try again.'), 'RESET_FAILED', 422);
        }

        $logger->log(NotificationChannel::Email, 'password_reset', $user->email, NotificationStatus::Sent, [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        return $this->success([], __('We sent a password reset link to :email.', ['email' => $user->email]));
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            // Anyone holding an old session has to sign in again with the new password.
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return $this->coded(__('This reset link is invalid or has expired. Please ask for a new one.'), 'RESET_INVALID', 422);
        }

        return $this->success([], __('Your password has been updated. You can log in now.'));
    }

    private function coded(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [],
            'code' => $code,
        ], $status);
    }
}
