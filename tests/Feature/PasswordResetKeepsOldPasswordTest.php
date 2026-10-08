<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetKeepsOldPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_password_keeps_working_until_a_new_one_is_set(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'old-password-1']);
        $hash = $user->fresh()->password;

        $this->postJson('/api/auth/forgot-password', ['email' => 'owner@example.test'])->assertOk();
        $this->postJson('/api/auth/forgot-password', ['email' => 'owner@example.test']); // asking twice changes nothing either

        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));

        $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => 'old-password-1'])
            ->assertOk()->assertJsonPath('success', true);
    }

    public function test_an_unused_reset_link_does_not_log_out_existing_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'old-password-1']);
        $token = $user->createToken('web')->plainTextToken;

        $this->postJson('/api/auth/forgot-password', ['email' => 'owner@example.test'])->assertOk();

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }
}
