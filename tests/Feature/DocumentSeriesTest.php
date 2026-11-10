<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DocumentSeriesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    private function own(string $typed)
    {
        return $this->actingAs($this->owner, 'sanctum')->putJson('/api/app/document-series', ['mode' => 'manual', 'next_number' => $typed]);
    }

    private function issue(string $type = 'factura', ?string $issuedAt = null)
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 50]);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', [
            'type' => $type,
            'issued_at' => $issuedAt ?? now()->toIso8601String(),
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

    public function test_a_company_starts_with_the_built_in_numbers(): void
    {
        $month = now()->format('Ym');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/app/document-series')->assertOk()
            ->assertJsonPath('data.mode', 'auto')
            ->assertJsonPath('data.next_number', "F-{$month}/00001")
            ->assertJsonPath('data.last_number', null)
            ->assertJsonPath('data.automatic_example', "F-{$month}/00001");

        $this->issue()->assertCreated()->assertJsonPath('data.number', "F-{$month}/00001");
    }

    public function test_the_typed_number_is_the_next_invoice_and_every_one_after_continues(): void
    {
        $this->own('ALP-00234')->assertOk()
            ->assertJsonPath('data.mode', 'manual')
            ->assertJsonPath('data.next_number', 'ALP-00234');

        $this->issue()->assertCreated()->assertJsonPath('data.number', 'ALP-00234');
        $this->issue()->assertCreated()->assertJsonPath('data.number', 'ALP-00235');

        // What the new-sale screen shows is what the next invoice gets.
        $this->getJson('/api/app/sales/preview?type=factura')->assertJsonPath('data.number', 'ALP-00236');
        $this->getJson('/api/app/document-series')->assertJsonPath('data.last_number', 'ALP-00235');
    }

    public function test_the_width_is_kept_and_the_series_does_not_restart_next_month(): void
    {
        $this->own('INV/0007')->assertOk();

        // An invoice dated last month and one dated now: one series, no restart when the month changes.
        $lastMonth = now()->subMonthNoOverflow()->endOfMonth()->startOfDay()->addHours(12)->toIso8601String();
        $this->issue('factura', $lastMonth)->assertCreated()->assertJsonPath('data.number', 'INV/0007');
        $this->issue()->assertCreated()->assertJsonPath('data.number', 'INV/0008');
    }

    public function test_a_number_without_a_prefix_and_a_width_that_grows_work(): void
    {
        $this->own('99')->assertOk();
        $this->issue()->assertCreated()->assertJsonPath('data.number', '99');
        $this->issue()->assertCreated()->assertJsonPath('data.number', '100');
    }

    public function test_the_series_never_repeats_a_number_that_already_exists(): void
    {
        $this->own('ALP-00010')->assertOk();
        $this->issue()->assertCreated()->assertJsonPath('data.number', 'ALP-00010');
        $this->issue()->assertCreated()->assertJsonPath('data.number', 'ALP-00011');

        // New letters, then back to the old ones starting low: the used numbers are skipped.
        $this->own('XYZ-1')->assertOk();
        $this->own('ALP-00010')->assertOk()->assertJsonPath('data.next_number', 'ALP-00012');
        $this->issue()->assertCreated()->assertJsonPath('data.number', 'ALP-00012');
    }

    public function test_with_the_same_letters_the_number_can_only_go_up(): void
    {
        $this->own('ALP-00050')->assertOk();
        $this->issue()->assertCreated();
        $this->issue()->assertCreated();

        $this->own('ALP-00040')->assertStatus(422)->assertJsonValidationErrors('next_number');
        $this->own('ALP-00051')->assertStatus(422);

        $this->own('ALP-00500')->assertOk();
        $this->issue()->assertCreated()->assertJsonPath('data.number', 'ALP-00500');
    }

    public function test_going_back_to_automatic_gives_the_built_in_numbers_and_leaves_their_counter_alone(): void
    {
        $month = now()->format('Ym');

        $this->issue()->assertCreated()->assertJsonPath('data.number', "F-{$month}/00001");
        $this->own('ALP-00234')->assertOk();
        $this->issue()->assertCreated()->assertJsonPath('data.number', 'ALP-00234');

        $this->putJson('/api/app/document-series', ['mode' => 'auto'])->assertOk()->assertJsonPath('data.mode', 'auto');
        $this->assertNull($this->company->fresh()->document_series);
        $this->issue()->assertCreated()->assertJsonPath('data.number', "F-{$month}/00002");
    }

    public function test_the_other_documents_always_keep_the_built_in_numbers(): void
    {
        $month = now()->format('Ym');
        $this->own('ALP-00234')->assertOk();

        $this->issue('albaran')->assertCreated()->assertJsonPath('data.number', "AL-{$month}/00001");
        $this->issue('quotation')->assertCreated()->assertJsonPath('data.number', "Q-{$month}/00001");
        $this->issue('proforma')->assertCreated()->assertJsonPath('data.number', "PF-{$month}/00001");

        // Anything the earlier version saved for them is ignored.
        $this->company->forceFill(['document_series' => ['albaran' => ['prefix' => 'DN', 'start' => 50], 'factura' => ['mode' => 'manual', 'prefix' => 'ALP-', 'width' => 5]]])->save();
        $this->issue('albaran')->assertCreated()->assertJsonPath('data.number', "AL-{$month}/00002");
    }

    public function test_the_number_is_checked(): void
    {
        foreach (['ALP-', 'ALP', 'AL P-00234', 'ALP#00234', 'ALP-0', 'ALP-000000000', str_repeat('A', 28).'-001'] as $bad) {
            $this->own($bad)->assertStatus(422)->assertJsonValidationErrors('next_number');
        }

        $this->putJson('/api/app/document-series', ['mode' => 'manual'])->assertStatus(422)->assertJsonValidationErrors('next_number');
        $this->putJson('/api/app/document-series', ['mode' => 'weekly'])->assertStatus(422)->assertJsonValidationErrors('mode');

        // Nothing was saved by the refused attempts.
        $this->assertNull($this->company->fresh()->document_series);
    }

    public function test_one_company_does_not_change_the_numbers_of_another(): void
    {
        $month = now()->format('Ym');
        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $stranger = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->own('ALP-00900')->assertOk();

        $this->actingAs($stranger, 'sanctum')->getJson('/api/app/document-series')->assertJsonPath('data.mode', 'auto')->assertJsonPath('data.next_number', "F-{$month}/00001");
    }

    public function test_who_may_see_and_change_the_numbering(): void
    {
        foreach (['manager', 'accountant'] as $role) {
            $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/team', [
                'name' => 'Marta Rivas',
                'email' => "{$role}@shop.test",
                'password' => 'secret-pass-1',
                'permissions' => Permissions::preset($role),
            ])->assertCreated();
        }

        // The manager can look at settings but not change them; the accountant has no settings at all.
        $manager = User::where('email', 'manager@shop.test')->firstOrFail();
        $this->actingAs($manager, 'sanctum')->getJson('/api/app/document-series')->assertOk();
        $this->putJson('/api/app/document-series', ['mode' => 'auto'])->assertForbidden();

        $accountant = User::where('email', 'accountant@shop.test')->firstOrFail();
        $this->actingAs($accountant, 'sanctum')->getJson('/api/app/document-series')->assertForbidden();
        $this->putJson('/api/app/document-series', ['mode' => 'auto'])->assertForbidden();
    }
}
