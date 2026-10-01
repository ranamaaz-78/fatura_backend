<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_registered_email_gets_a_reset_link_with_the_frontend_url(): void
    {
        Mail::fake();
        $user = User::factory()->businessAdmin()->create(['email' => 'owner@shop.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'owner@shop.test'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            return $mail->hasTo('owner@shop.test')
                && str_contains($mail->resetUrl, '/reset-password?token=')
                && str_contains($mail->resetUrl, 'email='.urlencode($user->email));
        });
    }

    public function test_the_email_renders_with_the_shared_layout(): void
    {
        $user = User::factory()->businessAdmin()->create(['name' => 'Ana Mora']);

        $html = (new PasswordResetMail($user, 'https://app.test/reset-password?token=abc&email=x'))->render();

        $this->assertStringContainsString('Ana Mora', $html);
        $this->assertStringContainsString('https://app.test/reset-password?token=abc', $html);
        $this->assertStringContainsString('expires in 60 minutes', $html);
    }

    public function test_an_unknown_email_is_told_so_and_nothing_is_sent(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@nowhere.test'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'ACCOUNT_NOT_FOUND');

        Mail::assertNothingSent();
    }

    public function test_a_disabled_user_cannot_ask_for_a_link(): void
    {
        Mail::fake();
        User::factory()->businessAdmin()->create(['email' => 'off@shop.test', 'status' => UserStatus::Disabled]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'off@shop.test'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'USER_DISABLED');

        Mail::assertNothingSent();
    }

    public function test_asking_twice_in_a_row_is_throttled(): void
    {
        Mail::fake();
        User::factory()->businessAdmin()->create(['email' => 'owner@shop.test']);

        $this->postJson('/api/auth/forgot-password', ['email' => 'owner@shop.test'])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'owner@shop.test'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'RESET_THROTTLED');
    }

    public function test_a_valid_token_sets_the_password_and_signs_other_sessions_out(): void
    {
        $user = User::factory()->businessAdmin()->create(['email' => 'owner@shop.test']);
        $user->createToken('api');
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'owner@shop.test',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_wrong_or_reused_token_is_rejected(): void
    {
        $user = User::factory()->businessAdmin()->create(['email' => 'owner@shop.test']);
        $token = Password::createToken($user);
        $payload = ['email' => 'owner@shop.test', 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'];

        $this->postJson('/api/auth/reset-password', $payload + ['token' => 'wrong'])
            ->assertStatus(422)->assertJsonPath('code', 'RESET_INVALID');

        $this->postJson('/api/auth/reset-password', $payload + ['token' => $token])->assertOk();
        $this->postJson('/api/auth/reset-password', $payload + ['token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'RESET_INVALID');
    }
}
