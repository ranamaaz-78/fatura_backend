<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\InviteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function invitedOwner(): User
    {
        return User::factory()->businessAdmin()->invited()->create([
            'email' => 'grace@northwind.test',
            'company_id' => Company::factory(),
        ]);
    }

    public function test_an_invited_owner_sets_a_password_and_receives_a_token(): void
    {
        $owner = $this->invitedOwner();
        $token = app(InviteService::class)->createToken($owner);

        $response = $this->postJson('/api/auth/set-password', [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role', 'business_admin')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email']]]);

        $owner->refresh();
        $this->assertTrue(Hash::check('secret-password', $owner->password));
        $this->assertSame(UserStatus::Active, $owner->status);
        $this->assertNotNull($owner->last_login_at);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'grace@northwind.test');
    }

    public function test_the_invite_token_is_single_use(): void
    {
        $owner = $this->invitedOwner();
        $token = app(InviteService::class)->createToken($owner);

        $body = [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ];

        $this->postJson('/api/auth/set-password', $body)->assertOk();

        $this->postJson('/api/auth/set-password', $body)
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVITE_INVALID');
    }

    public function test_an_invite_expires_after_48_hours(): void
    {
        $owner = $this->invitedOwner();
        $token = app(InviteService::class)->createToken($owner);

        Carbon::setTestNow(now()->addHours(49));

        $this->postJson('/api/auth/set-password', [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVITE_INVALID')
            ->assertJsonPath('support.email', config('fatura.support.email'));

        Carbon::setTestNow();
    }

    public function test_a_bad_token_is_refused(): void
    {
        $owner = $this->invitedOwner();

        $this->postJson('/api/auth/set-password', [
            'token' => 'not-a-real-token',
            'email' => $owner->email,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertStatus(422)->assertJsonPath('code', 'INVITE_INVALID');

        $this->assertNull($owner->fresh()->password);
    }

    public function test_the_password_must_be_confirmed_and_long_enough(): void
    {
        $owner = $this->invitedOwner();
        $token = app(InviteService::class)->createToken($owner);

        $this->postJson('/api/auth/set-password', [
            'token' => $token,
            'email' => $owner->email,
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
