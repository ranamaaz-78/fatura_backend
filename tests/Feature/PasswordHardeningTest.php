<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hash_made_with_old_settings_is_upgraded_at_sign_in(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'admin@fatura.test']);

        // A hash from before a cost change: valid, but not what the app makes today.
        $old = password_hash('password', PASSWORD_BCRYPT, ['cost' => 5]);
        DB::table('users')->where('id', $admin->id)->update(['password' => $old]);
        $this->assertTrue(Hash::needsRehash($old));

        $this->postJson('/api/auth/login', ['email' => 'admin@fatura.test', 'password' => 'password'])->assertOk();

        $stored = $admin->fresh()->password;
        $this->assertNotSame($old, $stored);
        $this->assertFalse(Hash::needsRehash($stored));
        $this->assertTrue(Hash::check('password', $stored));
    }

    public function test_a_current_hash_is_left_alone_at_sign_in(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'admin@fatura.test']);
        $before = $admin->password;

        $this->postJson('/api/auth/login', ['email' => 'admin@fatura.test', 'password' => 'password'])->assertOk();

        $this->assertSame($before, $admin->fresh()->password);
    }

    public function test_the_super_admin_seeder_never_falls_back_to_a_default_password(): void
    {
        config(['fatura.super_admin.email' => 'root@fatura.test', 'fatura.super_admin.password' => '']);

        $this->seed(SuperAdminSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'root@fatura.test']);
    }

    public function test_the_super_admin_seeder_does_not_reset_a_live_admin_when_the_password_is_missing(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'root@fatura.test']);
        $before = $admin->password;
        config(['fatura.super_admin.email' => 'root@fatura.test', 'fatura.super_admin.password' => null]);

        $this->seed(SuperAdminSeeder::class);

        $this->assertSame($before, $admin->fresh()->password);
    }

    public function test_the_super_admin_seeder_uses_the_configured_password(): void
    {
        config(['fatura.super_admin.email' => 'root@fatura.test', 'fatura.super_admin.password' => 'a-strong-pass-1']);

        $this->seed(SuperAdminSeeder::class);

        $this->assertTrue(Hash::check('a-strong-pass-1', User::where('email', 'root@fatura.test')->value('password')));
    }

    public function test_a_password_longer_than_bcrypt_reads_is_refused(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user, 'sanctum')->patchJson('/api/admin/password', [
            'current_password' => 'password',
            'password' => str_repeat('a', 73),
            'password_confirmation' => str_repeat('a', 73),
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->actingAs($user, 'sanctum')->patchJson('/api/admin/password', [
            'current_password' => 'password',
            'password' => str_repeat('b', 72),
            'password_confirmation' => str_repeat('b', 72),
        ])->assertOk();
    }
}
