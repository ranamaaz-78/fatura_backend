<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create(['email' => 'admin@platform.test', 'password' => 'old-password-1']);
    }

    public function test_the_platform_admin_changes_their_own_password(): void
    {
        $admin = $this->admin();

        // The wrong current password, the same password, and a short one are all refused.
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/password', ['current_password' => 'nope', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/password', ['current_password' => 'old-password-1', 'password' => 'old-password-1', 'password_confirmation' => 'old-password-1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/password', ['current_password' => 'old-password-1', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/password', ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'different'])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/admin/password', ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'admin@platform.test', 'password' => 'old-password-1'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => 'admin@platform.test', 'password' => 'new-password-1'])->assertOk()->assertJsonPath('data.role', 'super_admin');
    }

    public function test_changing_the_password_signs_the_other_devices_out_but_not_this_one(): void
    {
        $admin = $this->admin();
        $other = $admin->createToken('other-device');
        $mine = $admin->createToken('this-device');

        $this->withToken($mine->plainTextToken)
            ->patchJson('/api/admin/password', ['current_password' => 'old-password-1', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $mine->accessToken->id]);
    }

    public function test_the_route_is_only_for_signed_in_platform_admins(): void
    {
        $this->patchJson('/api/admin/password', ['current_password' => 'x', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertUnauthorized();

        $owner = User::factory()->businessAdmin()->create();
        $this->actingAs($owner, 'sanctum')->patchJson('/api/admin/password', ['current_password' => 'x', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertForbidden();
    }

    public function test_a_platform_admin_who_forgot_the_password_gets_a_link_and_can_set_a_new_one(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $session = $admin->createToken('old-session');

        $this->postJson('/api/auth/forgot-password', ['email' => 'admin@platform.test'])->assertOk();
        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo('admin@platform.test'));

        $token = Password::broker()->createToken($admin);
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'email' => 'admin@platform.test', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])->assertOk();

        $this->postJson('/api/auth/login', ['email' => 'admin@platform.test', 'password' => 'brand-new-pass'])->assertOk();
        // Whatever was open before is closed.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $session->accessToken->id]);
    }

    public function test_the_reset_email_really_renders_for_a_platform_admin(): void
    {
        $html = (new PasswordResetMail($this->admin(), 'https://example.test/reset-password?token=abc'))->render();

        $this->assertStringContainsString('https://example.test/reset-password?token=abc', $html);
    }

    public function test_the_server_command_sets_a_new_password_for_a_platform_admin_who_cannot_get_the_email(): void
    {
        $admin = $this->admin();
        $admin->update(['status' => 'disabled']);
        $session = $admin->createToken('old-session');

        $this->artisan('admin:reset-password', ['email' => 'admin@platform.test', '--password' => 'rescued-pass-1'])->assertSuccessful();

        $this->postJson('/api/auth/login', ['email' => 'admin@platform.test', 'password' => 'rescued-pass-1'])->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $session->accessToken->id]);

        // Not for company users, not for strangers, not for weak passwords.
        $owner = User::factory()->businessAdmin()->create(['email' => 'owner@shop.test']);
        $this->artisan('admin:reset-password', ['email' => 'owner@shop.test', '--password' => 'rescued-pass-1'])->assertFailed();
        $this->artisan('admin:reset-password', ['email' => 'nobody@x.test', '--password' => 'rescued-pass-1'])->assertFailed();
        $this->artisan('admin:reset-password', ['email' => 'admin@platform.test', '--password' => 'short'])->assertFailed();
        $this->assertNotNull($owner);
    }
}
