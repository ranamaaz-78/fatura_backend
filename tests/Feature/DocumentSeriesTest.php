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

    /** The form as it is sent. Unless a test sets "first" itself, this month begins where every month does. */
    private function series(array $override = []): array
    {
        $series = array_replace_recursive([
            'factura' => ['prefix' => 'INV', 'start' => 1],
            'albaran' => ['prefix' => 'DN', 'start' => 1],
            'quotation' => ['prefix' => 'QT', 'start' => 1],
            'proforma' => ['prefix' => 'PRO', 'start' => 1],
        ], $override);

        foreach ($series as $kind => $row) {
            $series[$kind]['first'] ??= $row['start'];
        }

        return ['series' => $series];
    }

    private function issue(string $type, ?string $issuedAt = null)
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

    public function test_a_company_starts_with_the_standard_letters_and_numbers(): void
    {
        $month = now()->format('Ym');
        $rows = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/app/document-series')->assertOk()->json('data.series'))->keyBy('kind');

        $this->assertSame(['factura', 'albaran', 'quotation', 'proforma'], $rows->keys()->all());
        $this->assertSame('F', $rows['factura']['prefix']);
        $this->assertSame('AL', $rows['albaran']['prefix']);
        $this->assertSame(1, $rows['factura']['start']);
        $this->assertSame(1, $rows['factura']['first']);
        $this->assertSame("F-{$month}/00001", $rows['factura']['next_number']);
        $this->assertSame(0, $rows['factura']['issued_this_month']);
    }

    public function test_new_documents_use_the_letters_and_start_the_owner_chose(): void
    {
        $month = now()->format('Ym');

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'acme', 'start' => 341]]))
            ->assertOk()
            ->assertJsonPath('data.series.0.prefix', 'ACME')
            ->assertJsonPath('data.series.0.next_number', "ACME-{$month}/00341");

        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "ACME-{$month}/00341");
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "ACME-{$month}/00342");
        $this->issue('albaran')->assertCreated()->assertJsonPath('data.number', "DN-{$month}/00001");

        // What the screen that makes a new sale shows is the same number it will get.
        $this->getJson('/api/app/sales/preview?type=factura')->assertJsonPath('data.number', "ACME-{$month}/00343");
    }

    public function test_the_start_can_be_raised_later_and_never_takes_a_number_back(): void
    {
        $month = now()->format('Ym');
        $this->actingAs($this->owner, 'sanctum');

        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00001");
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00002");

        // Raising it jumps the series forward...
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'F', 'start' => 500]]))->assertOk();
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00500");

        // ...lowering it again does not repeat a number that was used.
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'F', 'start' => 10]]))->assertOk();
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00501");
    }

    public function test_changing_the_letters_keeps_the_numbers_going_and_never_repeats_one(): void
    {
        $month = now()->format('Ym');
        $this->actingAs($this->owner, 'sanctum');

        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00001");
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'ACME', 'start' => 1]]))->assertOk();

        // The old invoice keeps its number; the next one carries on in the new letters.
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "ACME-{$month}/00002");
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'F', 'start' => 1]]))->assertOk();
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00003");
    }

    public function test_this_month_can_begin_at_one_number_and_every_next_month_at_another(): void
    {
        $month = now()->format('Ym');
        $lastMonth = now()->subMonth()->format('Ym');
        $this->actingAs($this->owner, 'sanctum');

        // This month's first invoice is 123; every other month begins at 1.
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'F', 'first' => 123, 'start' => 1]]))
            ->assertOk()
            ->assertJsonPath('data.series.0.first', 123)
            ->assertJsonPath('data.series.0.start', 1)
            ->assertJsonPath('data.series.0.next_number', "F-{$month}/00123");

        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00123");
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00124");

        // Another month's series is not touched by it: it begins at the usual start.
        $this->issue('factura', now()->subMonth()->endOfMonth()->toIso8601String())->assertCreated()->assertJsonPath('data.number', "F-{$lastMonth}/00001");
        $this->issue('albaran')->assertCreated()->assertJsonPath('data.number', "DN-{$month}/00001");
    }

    public function test_the_first_number_is_for_one_month_and_the_next_month_goes_back_to_the_usual_start(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'F', 'first' => 123, 'start' => 1]]))->assertOk();

        // Time passes: the month it was set for is now a month ago.
        $saved = $this->company->fresh()->document_series;
        $saved['factura']['first_period'] = (int) now()->subMonth()->format('Ym');
        $this->company->update(['document_series' => $saved]);

        $rows = collect($this->getJson('/api/app/document-series')->json('data.series'))->keyBy('kind');
        $this->assertSame(1, $rows['factura']['first'], 'the screen shows where this month begins, which is the usual start again');
        $this->assertSame(1, $rows['factura']['start']);
        $this->assertSame('F-'.now()->format('Ym').'/00001', $rows['factura']['next_number']);

        // Saving what the screen shows does not bring the old first number back.
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'F', 'start' => 1, 'first' => 1]]))->assertOk();
        $this->assertArrayNotHasKey('first', $this->company->fresh()->document_series['factura']);
    }

    public function test_a_first_number_below_what_was_issued_never_repeats_a_number(): void
    {
        $month = now()->format('Ym');
        $this->actingAs($this->owner, 'sanctum');

        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00001");
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00002");

        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'F', 'first' => 1, 'start' => 1]]))->assertOk();
        $this->issue('factura')->assertCreated()->assertJsonPath('data.number', "F-{$month}/00003");
    }

    public function test_the_form_is_checked(): void
    {
        $this->actingAs($this->owner, 'sanctum');

        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'IN V']]))->assertStatus(422)->assertJsonValidationErrors('series.factura.prefix');
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'WAYTOOLONGPREFIX']]))->assertStatus(422)->assertJsonValidationErrors('series.factura.prefix');
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'A/B']]))->assertStatus(422)->assertJsonValidationErrors('series.factura.prefix');
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['start' => 0]]))->assertStatus(422)->assertJsonValidationErrors('series.factura.start');
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['start' => 10000000]]))->assertStatus(422)->assertJsonValidationErrors('series.factura.start');
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['first' => 0]]))->assertStatus(422)->assertJsonValidationErrors('series.factura.first');
        $this->putJson('/api/app/document-series', $this->series(['factura' => ['first' => 10000000]]))->assertStatus(422)->assertJsonValidationErrors('series.factura.first');
        $this->putJson('/api/app/document-series', ['series' => ['factura' => ['prefix' => 'INV', 'start' => 1]]])->assertStatus(422);
    }

    public function test_two_kinds_cannot_share_letters(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/document-series', $this->series(['albaran' => ['prefix' => 'inv']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('series.albaran.prefix');

        // The letters of the retired credit note stay taken.
        $this->putJson('/api/app/document-series', $this->series(['quotation' => ['prefix' => 'AB']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('series.quotation.prefix');

        // Nothing was saved by the refused attempts.
        $this->assertNull($this->company->fresh()->document_series);
    }

    public function test_one_company_does_not_change_the_numbers_of_another(): void
    {
        $month = now()->format('Ym');
        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $stranger = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/document-series', $this->series(['factura' => ['prefix' => 'ACME', 'start' => 900]]))->assertOk();

        $rows = collect($this->actingAs($stranger, 'sanctum')->getJson('/api/app/document-series')->json('data.series'))->keyBy('kind');
        $this->assertSame('F', $rows['factura']['prefix']);
        $this->assertSame("F-{$month}/00001", $rows['factura']['next_number']);
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
        $this->putJson('/api/app/document-series', $this->series())->assertForbidden();

        $accountant = User::where('email', 'accountant@shop.test')->firstOrFail();
        $this->actingAs($accountant, 'sanctum')->getJson('/api/app/document-series')->assertForbidden();
        $this->putJson('/api/app/document-series', $this->series())->assertForbidden();
    }
}
