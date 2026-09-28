<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $this->owner = User::factory()->businessAdmin()->create([
            'company_id' => $company->id,
            'password' => 'password',
        ]);
    }

    public function test_the_owner_can_change_their_password(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/password', [
                'current_password' => 'password',
                'password' => 'new-secret-9',
                'password_confirmation' => 'new-secret-9',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-secret-9', $this->owner->fresh()->password));
    }

    public function test_the_current_password_must_match(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/password', [
                'current_password' => 'wrong',
                'password' => 'new-secret-9',
                'password_confirmation' => 'new-secret-9',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('password', $this->owner->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }
}
