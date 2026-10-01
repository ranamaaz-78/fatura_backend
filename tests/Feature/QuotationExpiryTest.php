<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuotationExpiryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fatura.timezone' => 'UTC']);

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function line(): array
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 10]);

        return [[
            'product_id' => $product->id,
            'article' => $product->article,
            'quantity' => 1,
            'unit_price' => 1000,
            'discount_percent' => 0,
            'iva_percent' => 21,
        ]];
    }

    private function payload(string $type, ?string $issuedAt = null): array
    {
        return [
            'type' => $type,
            'issued_at' => $issuedAt ?? now()->toIso8601String(),
            'payment_status' => 'pending',
            'save_customer' => false,
            'client_name' => 'Counter sale',
            'lines' => $this->line(),
        ];
    }

    private function issue(string $type = 'quotation', ?string $issuedAt = null): int
    {
        return (int) $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload($type, $issuedAt))
            ->assertCreated()
            ->json('data.id');
    }

    public function test_a_new_quotation_is_valid_for_a_week_from_its_date(): void
    {
        Carbon::setTestNow('2026-10-01 14:30:00');

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/sales', $this->payload('quotation'))
            ->assertCreated()
            ->assertJsonPath('data.is_expired', false);

        $this->assertSame('2026-10-08', Carbon::parse($response->json('data.expires_at'))->toDateString());
        $this->assertTrue(SalesDocument::first()->expires_at->equalTo(Carbon::parse('2026-10-08 23:59:59')));
    }

    public function test_the_week_counts_from_the_date_written_on_the_quotation_not_the_day_it_was_typed(): void
    {
        Carbon::setTestNow('2026-10-01 17:50:00');

        // Dated 24 September, typed on 1 October: its week ends on 1 October.
        $id = $this->issue('quotation', '2026-09-24T17:49:00Z');

        $this->assertTrue(SalesDocument::find($id)->expires_at->equalTo(Carbon::parse('2026-10-01 23:59:59')));

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/app/sales/{$id}")->assertJsonPath('data.is_expired', false);

        Carbon::setTestNow('2026-10-02 00:00:01');

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/app/sales/{$id}")->assertJsonPath('data.is_expired', true);
    }

    public function test_a_quotation_dated_more_than_a_week_ago_is_expired_from_the_start(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        $id = $this->issue('quotation', '2026-10-05T10:00:00Z');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura', 'payment_status' => 'pending'])
            ->assertStatus(422);
    }

    public function test_changing_the_date_on_an_open_quotation_moves_the_end_of_its_week(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $id = $this->issue();

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->payload('quotation', '2026-10-03T09:00:00Z'))
            ->assertOk();

        $this->assertSame('2026-10-10', SalesDocument::find($id)->expires_at->toDateString());
    }

    public function test_the_end_of_the_day_follows_the_business_timezone(): void
    {
        config(['fatura.timezone' => 'Asia/Karachi']);
        Carbon::setTestNow('2026-10-01 18:00:00');

        // 24 September 22:49 in Karachi is 17:49 UTC. Its week still ends on 1 October, Karachi time.
        $id = $this->issue('quotation', '2026-09-24T17:49:00Z');
        $expires = SalesDocument::find($id)->expires_at;

        $this->assertSame('2026-10-01 23:59:59', $expires->copy()->setTimezone('Asia/Karachi')->format('Y-m-d H:i:s'));

        // 23:00 UTC on 1 October is already 04:00 on 2 October in Karachi: over.
        Carbon::setTestNow('2026-10-01 23:00:00');
        $this->assertTrue(SalesDocument::find($id)->isExpired());

        // 18:30 UTC is 23:30 in Karachi, still the last day.
        Carbon::setTestNow('2026-10-01 18:30:00');
        $this->assertFalse(SalesDocument::find($id)->isExpired());
    }

    public function test_other_documents_never_expire(): void
    {
        foreach (['factura', 'albaran', 'proforma'] as $type) {
            $this->actingAs($this->owner, 'sanctum')
                ->postJson('/api/app/sales', $this->payload($type))
                ->assertCreated()
                ->assertJsonPath('data.expires_at', null)
                ->assertJsonPath('data.is_expired', false);
        }
    }

    public function test_it_can_still_be_edited_and_converted_on_the_last_day(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $id = $this->issue();

        Carbon::setTestNow('2026-10-08 23:00:00');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->payload('quotation'))
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura', 'payment_status' => 'pending'])
            ->assertCreated();
    }

    public function test_after_the_week_it_expires_and_can_neither_be_edited_nor_converted(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $id = $this->issue();

        Carbon::setTestNow('2026-10-09 00:00:01');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/app/sales/{$id}")
            ->assertOk()
            ->assertJsonPath('data.is_expired', true);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", $this->payload('quotation'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        foreach (['factura', 'albaran'] as $target) {
            $this->actingAs($this->owner, 'sanctum')
                ->postJson("/api/app/sales/{$id}/convert", ['type' => $target, 'payment_status' => 'pending'])
                ->assertStatus(422);
        }

        // Nothing was issued, and the expired quotation is still there to look at and print.
        $this->assertSame(1, SalesDocument::count());
        $this->assertNull(SalesDocument::first()->converted_at);
    }

    public function test_a_converted_quotation_is_not_reported_as_expired(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $id = $this->issue();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura', 'payment_status' => 'pending'])
            ->assertCreated();

        Carbon::setTestNow('2026-11-01 09:00:00');

        $this->assertFalse(SalesDocument::find($id)->isExpired());
    }

    public function test_the_length_of_the_week_comes_from_settings(): void
    {
        config(['fatura.quotations.valid_days' => 3]);
        Carbon::setTestNow('2026-10-01 10:00:00');

        $id = $this->issue();

        $this->assertSame('2026-10-04', SalesDocument::find($id)->expires_at->toDateString());
    }
}
