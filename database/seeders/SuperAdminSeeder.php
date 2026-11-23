<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('fatura.super_admin.email');

        if (blank($email)) {
            $this->command?->warn('SUPER_ADMIN_EMAIL is not set; skipping super admin seed.');

            return;
        }

        $password = config('fatura.super_admin.password');

        // Never fall back to a guessable default: a re-run would otherwise reset a live admin to it.
        if (blank($password)) {
            $this->command?->warn('SUPER_ADMIN_PASSWORD is not set; skipping super admin seed.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('fatura.super_admin.name'),
                'password' => $password,
                'role' => UserRole::SuperAdmin,
                'status' => UserStatus::Active,
                'company_id' => null,
                'email_verified_at' => now(),
            ],
        );
    }
}
