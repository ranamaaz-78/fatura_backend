<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_super_admin_signs_in_and_gets_a_token(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'admin@fatura.test']);

        $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'super_admin')
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_a_wrong_password_says_the_password_is_wrong(): void
    {
        User::factory()->superAdmin()->create(['email' => 'admin@fatura.test']);

        $this->postJson('/api/auth/login', [
            'email' => 'admin@fatura.test',
            'password' => 'wrong',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'WRONG_PASSWORD')
            ->assertJsonPath('message', 'The password is incorrect.')
            ->assertJsonValidationErrors('password');
    }

    public function test_an_email_with_no_account_says_so(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@fatura.test',
            'password' => 'whatever-1',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_NOT_FOUND')
            ->assertJsonPath('message', 'No account is registered with this email address.')
            ->assertJsonValidationErrors('email');
    }

    public function test_an_owner_who_never_set_a_password_cannot_sign_in(): void
    {
        $owner = User::factory()->businessAdmin()->invited()->create([
            'company_id' => Company::factory(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $owner->email,
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PASSWORD_NOT_SET');
    }

    public function test_a_disabled_user_is_rejected(): void
    {
        $user = User::factory()->businessAdmin()->create([
            'status' => UserStatus::Disabled,
            'company_id' => Company::factory(),
        ]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'USER_DISABLED');
    }

    public function test_a_user_of_a_suspended_company_is_rejected(): void
    {
        $user = User::factory()->businessAdmin()->create([
            'company_id' => Company::factory()->suspended(),
        ]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_SUSPENDED');
    }

    public function test_repeated_failures_are_throttled(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'admin@fatura.test']);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $token = $this->postJson('/api/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->json('data.token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // The guard caches the resolved user between requests in a single test.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
