<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The way back in for a platform admin who forgot the password and cannot get the reset email (mail down, or the
 * address is no longer theirs). It is run on the server, so only someone with access to the server can use it.
 */
class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password {email? : The platform admin (default: SUPER_ADMIN_EMAIL)} {--password= : The new password (asked for when left out)}';

    protected $description = 'Set a new password for a platform admin who cannot sign in, and sign them out everywhere';

    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?: config('fatura.super_admin.email'));

        $admin = User::where('email', $email)->where('role', UserRole::SuperAdmin->value)->first();

        if ($admin === null) {
            $this->error("No platform admin with the email \"{$email}\".");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: $this->secret('New password (at least 8 characters)'));

        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:8', 'max:100']]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $admin->forceFill(['password' => $password, 'status' => UserStatus::Active])->save();
        $admin->tokens()->delete();

        $this->info("Password changed for {$admin->email}. Every session of theirs was closed.");

        return self::SUCCESS;
    }
}
