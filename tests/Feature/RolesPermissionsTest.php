<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\TeamMemberMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RolesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->company = Company::factory()->create();
        $this->subscription = Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    /** A member made the way the owner makes one: through the team screen, from a role and its ticks. */
    private function member(string $role, ?array $permissions = null, string $email = 'member@shop.test'): User
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
            'name' => 'Marta Rivas',
            'email' => $email,
            'password' => 'secret-pass-1',
            'permissions' => $permissions ?? Permissions::preset($role),
        ])->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    private function sale(string $type, User $as)
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 50]);

        return $this->actingAs($as, 'sanctum')->postJson('/api/app/sales', [
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => [[
                'product_id' => $product->id,
                'article' => $product->article,
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ]);
    }

    public function test_the_catalogue_and_the_three_presets_are_served_to_the_owner_only(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/team')
            ->assertOk()
            ->assertJsonPath('data.catalogue.modules.invoices', ['view', 'create', 'pay', 'void'])
            ->assertJsonPath('data.catalogue.modules.quotes', ['view', 'create', 'update'])
            ->assertJsonPath('data.catalogue.modules.proformas', ['view', 'create', 'pay'])
            ->assertJsonStructure(['data' => ['catalogue' => ['presets' => ['manager', 'cashier', 'accountant']], 'members']]);

        $cashier = $this->member('cashier');
        $this->actingAs($cashier, 'sanctum')->getJson('/api/app/team')->assertForbidden();
    }

    public function test_a_member_is_created_with_the_ticks_and_emailed_their_login(): void
    {
        $member = $this->member('cashier');

        $this->assertSame('staff', $member->role->value);
        $this->assertSame($this->company->id, $member->company_id);
        $this->assertSame('cashier', $member->team_role);
        $this->assertEqualsCanonicalizing(Permissions::preset('cashier'), $member->permissions);

        Mail::assertSent(TeamMemberMail::class, fn (TeamMemberMail $mail) => $mail->hasTo('member@shop.test')
            && $mail->password === 'secret-pass-1'
            && $mail->created);

        // Ticking something else makes it a custom role.
        $custom = $this->member('cashier', [...Permissions::preset('cashier'), 'reports.view'], 'custom@shop.test');
        $this->assertSame('custom', $custom->team_role);
    }

    public function test_only_real_permissions_a_free_email_and_a_proper_password_are_accepted(): void
    {
        $base = ['name' => 'X', 'email' => 'x@shop.test', 'password' => 'secret-pass-1', 'permissions' => ['invoices.view']];

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [...$base, 'permissions' => ['invoices.fly']])->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [...$base, 'password' => 'short'])->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [...$base, 'email' => $this->owner->email])->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [...$base, 'permissions' => []])->assertStatus(422);
    }

    public function test_a_cashier_sells_but_cannot_void_or_open_reports(): void
    {
        $cashier = $this->member('cashier');

        $created = $this->sale('factura', $cashier)->assertCreated();
        $this->actingAs($cashier, 'sanctum')->postJson('/api/app/sales/'.$created->json('data.id').'/void', ['reason' => 'Mistake'])
            ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN_PERMISSION');
        $this->actingAs($cashier, 'sanctum')->getJson('/api/app/reports/sales')->assertForbidden();
        $this->actingAs($cashier, 'sanctum')->deleteJson('/api/app/products/1')->assertForbidden();
        // A proforma is view-only for a cashier.
        $this->sale('proforma', $cashier)->assertForbidden();
    }

    public function test_an_accountant_reads_but_cannot_issue_documents(): void
    {
        $accountant = $this->member('accountant');
        $existing = $this->sale('factura', $this->owner)->assertCreated();

        $this->sale('factura', $accountant)->assertForbidden();
        $this->actingAs($accountant, 'sanctum')->getJson('/api/app/sales/'.$existing->json('data.id'))->assertOk();
        $this->actingAs($accountant, 'sanctum')->getJson('/api/app/reports/sales')->assertOk();
        $this->actingAs($accountant, 'sanctum')->getJson('/api/app/payments')->assertOk();
        $this->actingAs($accountant, 'sanctum')->postJson('/api/app/customers', ['name' => 'Nope'])->assertForbidden();
    }

    public function test_the_document_list_shows_only_the_kinds_the_member_may_view(): void
    {
        $this->sale('factura', $this->owner)->assertCreated();
        $this->sale('quotation', $this->owner)->assertCreated();

        $quotesOnly = $this->member('cashier', ['quotes.view'], 'quotes@shop.test');

        $types = collect($this->actingAs($quotesOnly, 'sanctum')->getJson('/api/app/sales')->assertOk()->json('data.items'))->pluck('type')->unique()->all();
        $this->assertSame(['quotation'], $types);

        $this->actingAs($quotesOnly, 'sanctum')->getJson('/api/app/sales?type=factura')->assertForbidden();
    }

    public function test_a_member_cannot_manage_the_team_the_language_or_the_subscription(): void
    {
        $manager = $this->member('manager');

        $this->actingAs($manager, 'sanctum')->postJson('/api/app/team', ['name' => 'X', 'email' => 'y@shop.test', 'password' => 'secret-pass-1', 'permissions' => ['invoices.view']])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->patchJson('/api/app/company/locale', ['locale' => 'es'])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->patchJson('/api/app/company/document-locale', ['document_locale' => 'es'])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson('/api/app/subscription')->assertForbidden();
        // The manager preset leaves the settings to look at, not to change.
        $this->actingAs($manager, 'sanctum')->patchJson('/api/app/company', ['name' => 'Hacked'])->assertForbidden();
    }

    public function test_the_owner_edits_disables_resets_and_removes_members_of_their_own_company_only(): void
    {
        $member = $this->member('cashier');

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/team/{$member->id}", ['permissions' => [...Permissions::preset('cashier'), 'reports.view']])
            ->assertOk()->assertJsonPath('data.team_role', 'custom');

        // The member is signed in; switching them off signs them out.
        $token = $member->createToken('api')->plainTextToken;
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/team/{$member->id}/status", ['status' => 'disabled'])->assertOk();
        $this->assertSame(0, $member->tokens()->count());
        $this->assertNotEmpty($token);
        $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => 'secret-pass-1'])->assertForbidden()->assertJsonPath('code', 'USER_DISABLED');

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/team/{$member->id}/status", ['status' => 'active'])->assertOk();

        // A new password is emailed and works.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/team/{$member->id}/password", ['password' => 'brand-new-pass'])->assertOk();
        Mail::assertSent(TeamMemberMail::class, fn (TeamMemberMail $mail) => $mail->password === 'brand-new-pass' && ! $mail->created);
        $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => 'brand-new-pass'])->assertOk();

        // Never the owner, never another company.
        $other = User::factory()->create(['role' => 'staff', 'company_id' => Company::factory()->create()->id]);
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/team/{$other->id}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/app/team/{$this->owner->id}")->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/app/team/{$member->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    public function test_a_member_of_a_suspended_company_cannot_sign_in_and_suspending_signs_everyone_out(): void
    {
        $member = $this->member('manager');
        $memberToken = $member->createToken('api');
        $ownerToken = $this->owner->createToken('api');

        $this->actingAs(User::factory()->superAdmin()->create(), 'sanctum')
            ->patchJson("/api/admin/companies/{$this->company->id}/status", ['status' => CompanyStatus::Suspended->value])
            ->assertOk();

        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(0, $this->owner->tokens()->count());
        $this->assertNotNull($memberToken);
        $this->assertNotNull($ownerToken);

        $this->postJson('/api/auth/login', ['email' => 'member@shop.test', 'password' => 'secret-pass-1'])->assertForbidden()->assertJsonPath('code', 'COMPANY_SUSPENDED');
    }

    public function test_when_the_subscription_ends_the_team_is_locked_out_but_the_owner_can_still_renew(): void
    {
        $member = $this->member('manager');
        $this->owner->update(['password' => 'owner-pass-1']);

        $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => 'secret-pass-1'])->assertOk();

        $this->subscription->update(['status' => SubscriptionStatus::Cancelled, 'ends_at' => now()->subDay()]);

        // The team cannot sign in...
        $this->postJson('/api/auth/login', ['email' => $member->email, 'password' => 'secret-pass-1'])
            ->assertForbidden()->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
        // ...and a session they already had is refused.
        $this->actingAs($member, 'sanctum')->getJson('/api/app/dashboard')->assertStatus(402);

        // The owner still gets in, and sees the Subscription page.
        $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'owner-pass-1'])->assertOk();
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/subscription')->assertOk();
    }

    public function test_a_disabled_member_with_a_live_session_is_refused_and_signed_out(): void
    {
        $member = $this->member('manager');
        $member->update(['status' => 'disabled']);
        $member->createToken('api');

        $this->actingAs($member, 'sanctum')->getJson('/api/app/dashboard')->assertForbidden()->assertJsonPath('code', 'USER_DISABLED');
    }

    public function test_staff_from_before_permissions_existed_count_as_managers_and_me_lists_what_a_person_may_do(): void
    {
        $legacy = User::factory()->create(['role' => 'staff', 'company_id' => $this->company->id, 'permissions' => null]);
        $this->assertEqualsCanonicalizing(Permissions::preset('manager'), $legacy->permissionList());

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/me')->assertOk()
            ->assertJsonPath('data.permissions', Permissions::all());

        $cashier = $this->member('cashier');
        $mine = $this->actingAs($cashier, 'sanctum')->getJson('/api/app/me')->assertOk()->json('data.permissions');
        $this->assertEqualsCanonicalizing(Permissions::preset('cashier'), $mine);
    }

    public function test_the_owner_reads_every_document_kind_and_the_catalogue_is_consistent(): void
    {
        foreach (['factura', 'albaran', 'quotation', 'proforma'] as $type) {
            $this->sale($type, $this->owner)->assertCreated();
        }
        $this->assertSame(4, SalesDocument::count());

        // Every preset only uses permissions that exist.
        foreach (Permissions::presetKeys() as $key) {
            $this->assertSame([], array_diff(Permissions::preset($key), Permissions::all()), $key);
        }
    }

    public function test_the_owner_can_save_their_own_version_of_a_role_for_the_company(): void
    {
        $custom = [...Permissions::preset('cashier'), 'reports.view'];

        $this->actingAs($this->owner, 'sanctum')->putJson('/api/app/team/roles/cashier', ['permissions' => $custom])
            ->assertOk()
            ->assertJsonPath('data.customized', ['cashier']);

        // The screen now offers that version, and a member made from it is labelled Cashier, not Custom.
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/team')
            ->assertJsonPath('data.catalogue.customized', ['cashier'])
            ->assertJsonPath('data.catalogue.presets.cashier', Permissions::normalize($custom));

        $member = $this->member('cashier', $custom, 'saved-role@shop.test');
        $this->assertSame('cashier', $member->team_role);

        // Members that already existed keep what they were given.
        $before = $this->member('cashier', null, 'before@shop.test');
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/app/team/roles/cashier', ['permissions' => ['invoices.view']])->assertOk();
        $this->assertEqualsCanonicalizing(Permissions::preset('cashier'), $before->fresh()->permissions);

        // Another company is not affected.
        $other = Company::factory()->create();
        $this->assertSame(Permissions::presets(), Permissions::presetsFor($other));

        // Back to the standard.
        $this->actingAs($this->owner, 'sanctum')->deleteJson('/api/app/team/roles/cashier')
            ->assertOk()->assertJsonPath('data.customized', []);
        $this->assertSame(Permissions::presets(), Permissions::presetsFor($this->company->fresh()));
    }

    public function test_only_the_owner_saves_roles_and_only_real_roles_and_permissions(): void
    {
        $manager = $this->member('manager');

        $this->actingAs($manager, 'sanctum')->putJson('/api/app/team/roles/cashier', ['permissions' => ['invoices.view']])->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/app/team/roles/boss', ['permissions' => ['invoices.view']])->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/app/team/roles/cashier', ['permissions' => ['invoices.fly']])->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/app/team/roles/cashier', ['permissions' => []])->assertStatus(422);
    }

    public function test_the_role_the_owner_picked_is_kept_even_when_the_ticks_are_changed(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
            'name' => 'Marta Rivas',
            'email' => 'picked@shop.test',
            'password' => 'secret-pass-1',
            'team_role' => 'cashier',
            'permissions' => [...Permissions::preset('cashier'), 'reports.view'],
        ])->assertCreated()->assertJsonPath('data.team_role', 'cashier');

        $member = User::where('email', 'picked@shop.test')->firstOrFail();
        $this->assertSame('cashier', $member->team_role);

        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/team/{$member->id}", ['team_role' => 'accountant', 'permissions' => ['invoices.view']])
            ->assertOk()->assertJsonPath('data.team_role', 'accountant');

        // Only real roles are accepted.
        $this->actingAs($this->owner, 'sanctum')->patchJson("/api/app/team/{$member->id}", ['team_role' => 'boss'])->assertStatus(422);
    }

    public function test_the_login_email_really_renders(): void
    {
        $member = $this->member('cashier');

        foreach ([true, false] as $created) {
            $html = (new TeamMemberMail($member->fresh(), 'secret-pass-1', $created))->render();

            $this->assertStringContainsString('member@shop.test', $html);
            $this->assertStringContainsString('secret-pass-1', $html);
            $this->assertStringContainsString(config('fatura.support.email'), $html);
        }
    }

    public function test_nothing_is_offered_that_cannot_be_done_and_old_ticks_move_to_the_new_names(): void
    {
        $modules = Permissions::modules();

        // A document cannot be edited once issued; only a quotation can.
        foreach (['invoices', 'delivery_notes', 'proformas'] as $area) {
            $this->assertNotContains('update', $modules[$area], $area);
            $this->assertNotContains('delete', $modules[$area], $area);
        }
        $this->assertContains('update', $modules['quotes']);
        $this->assertNotContains('delete', $modules['quotes']);
        $this->assertNotContains('void', $modules['proformas']);
        // Pages that only show things have nothing to tick but "view".
        foreach (['dashboard', 'payments', 'stock', 'reports'] as $area) {
            $this->assertSame(['view'], $modules[$area], $area);
        }

        $this->assertEqualsCanonicalizing(
            ['invoices.view', 'invoices.pay', 'invoices.void', 'delivery_notes.pay', 'proformas.pay'],
            Permissions::migrateLegacy(['invoices.view', 'invoices.update', 'invoices.delete', 'delivery_notes.update', 'proformas.update', 'quotes.delete', 'stock.create']),
        );
        $this->assertEqualsCanonicalizing(['invoices.pay', 'delivery_notes.pay', 'proformas.pay'], Permissions::migrateLegacy(['payments.update']));
    }

    public function test_recording_a_payment_needs_pay_and_voiding_needs_void(): void
    {
        $sale = $this->sale('factura', $this->owner)->assertCreated();
        $id = $sale->json('data.id');

        $viewOnly = $this->member('cashier', ['invoices.view'], 'view-only@shop.test');
        $this->actingAs($viewOnly, 'sanctum')->patchJson("/api/app/sales/{$id}/payment", ['payment_status' => 'paid'])->assertForbidden();

        $payer = $this->member('cashier', ['invoices.view', 'invoices.pay'], 'payer@shop.test');
        $cash = (int) \App\Models\CompanyPaymentMethod::query()->where('name', 'Cash')->value('id');
        $this->actingAs($payer, 'sanctum')->patchJson("/api/app/sales/{$id}/payment", ['payment_status' => 'paid', 'payment_method_id' => $cash])->assertOk();
        $this->actingAs($payer, 'sanctum')->postJson("/api/app/sales/{$id}/void", ['reason' => 'Mistake'])->assertForbidden();

        $voider = $this->member('cashier', ['invoices.view', 'invoices.void'], 'voider@shop.test');
        $this->actingAs($voider, 'sanctum')->postJson("/api/app/sales/{$id}/void", ['reason' => 'Mistake'])->assertOk();
    }
}
