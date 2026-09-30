<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\SalesDocument;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PrintableNotesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    /** @param  array<string, mixed>  $extra */
    private function saveTemplate(string $type, array $extra = []): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')->putJson("/api/app/print-templates/{$type}", array_merge([
            'primary_color' => '#004ac6',
            'font_key' => 'geist',
            'footer_notes' => 'Terms.',
            'show_logo' => true,
            'show_signature' => false,
        ], $extra));
    }

    /** @param  array<string, mixed>  $extra */
    private function issue(string $type, array $extra = []): TestResponse
    {
        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 50]);

        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/app/sales', array_merge([
            'type' => $type,
            'issued_at' => now()->toIso8601String(),
            'payment_status' => 'pending',
            'client_name' => 'Ada Client',
            'lines' => [[
                'product_id' => $product->id,
                'article' => $product->article,
                'quantity' => 1,
                'unit_price' => 1000,
                'discount_percent' => 0,
                'iva_percent' => 21,
            ]],
        ], $extra));
    }

    public function test_every_printable_starts_with_an_empty_note(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/print-templates')
            ->assertOk()
            ->assertJsonPath('data.templates.0.notes', '')
            ->assertJsonPath('data.templates.3.notes', '');
    }

    public function test_the_note_is_saved_per_type_and_leaves_the_others_alone(): void
    {
        $this->saveTemplate('factura', ['notes' => "  Bank: ES00 1234\nPay in 30 days.  "])
            ->assertOk()
            ->assertJsonPath('data.templates.0.notes', "Bank: ES00 1234\nPay in 30 days.")
            ->assertJsonPath('data.templates.1.notes', '')
            ->assertJsonPath('data.templates.2.notes', '');
    }

    public function test_saving_without_a_notes_field_keeps_the_saved_note(): void
    {
        $this->saveTemplate('factura', ['notes' => 'Keep me.'])->assertOk();

        $this->saveTemplate('factura', ['primary_color' => '#be123c'])
            ->assertOk()
            ->assertJsonPath('data.templates.0.primary_color', '#be123c')
            ->assertJsonPath('data.templates.0.notes', 'Keep me.');
    }

    public function test_the_note_can_be_cleared_and_is_capped(): void
    {
        $this->saveTemplate('factura', ['notes' => 'Something'])->assertOk();

        $this->saveTemplate('factura', ['notes' => ''])
            ->assertOk()
            ->assertJsonPath('data.templates.0.notes', '');

        $this->saveTemplate('factura', ['notes' => str_repeat('x', 2001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notes']);
    }

    public function test_reset_clears_the_note_and_copy_shares_it(): void
    {
        $this->saveTemplate('factura', ['notes' => 'Shared note.'])->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/print-templates/factura/copy', ['types' => ['quotation']])
            ->assertOk()
            ->assertJsonPath('data.templates.2.notes', 'Shared note.');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/print-templates/factura/reset')
            ->assertOk()
            ->assertJsonPath('data.templates.0.notes', '');
    }

    public function test_a_new_document_is_stamped_with_the_note_of_its_type(): void
    {
        $this->saveTemplate('factura', ['notes' => 'Invoice note.'])->assertOk();
        $this->saveTemplate('quotation', ['notes' => 'Quote note.'])->assertOk();

        $this->issue('factura')->assertCreated()->assertJsonPath('data.notes', 'Invoice note.');
        $this->issue('quotation')->assertCreated()->assertJsonPath('data.notes', 'Quote note.');
        $this->issue('albaran')->assertCreated()->assertJsonPath('data.notes', null);
    }

    public function test_a_note_typed_on_the_document_is_ignored(): void
    {
        $this->saveTemplate('factura', ['notes' => 'From Printables.'])->assertOk();

        $this->issue('factura', ['notes' => 'Sneaky note'])
            ->assertCreated()
            ->assertJsonPath('data.notes', 'From Printables.');

        $this->saveTemplate('albaran', ['notes' => ''])->assertOk();
        $this->issue('albaran', ['notes' => 'Sneaky note'])
            ->assertCreated()
            ->assertJsonPath('data.notes', null);
    }

    public function test_an_issued_document_keeps_its_note_when_printables_changes(): void
    {
        $this->saveTemplate('factura', ['notes' => 'First wording.'])->assertOk();
        $id = $this->issue('factura')->assertCreated()->json('data.id');

        $this->saveTemplate('factura', ['notes' => 'Second wording.'])->assertOk();

        $this->assertSame('First wording.', SalesDocument::findOrFail($id)->notes);
        $this->issue('factura')->assertCreated()->assertJsonPath('data.notes', 'Second wording.');
    }

    public function test_a_quotation_keeps_its_note_when_edited_and_the_invoice_takes_its_own(): void
    {
        $this->saveTemplate('quotation', ['notes' => 'Quote wording.'])->assertOk();
        $this->saveTemplate('factura', ['notes' => 'Invoice wording.'])->assertOk();
        $id = $this->issue('quotation')->assertCreated()->json('data.id');

        $this->saveTemplate('quotation', ['notes' => 'Changed later.'])->assertOk();

        $product = Product::factory()->create(['company_id' => $this->company->id, 'quantity' => 50]);
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/sales/{$id}", [
                'issued_at' => now()->toIso8601String(),
                'client_name' => 'Ada Client',
                'notes' => 'Trying to change it',
                'lines' => [[
                    'product_id' => $product->id,
                    'article' => $product->article,
                    'quantity' => 2,
                    'unit_price' => 1000,
                    'discount_percent' => 0,
                    'iva_percent' => 21,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Quote wording.');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/app/sales/{$id}/convert", ['type' => 'factura', 'payment_status' => 'pending'])
            ->assertCreated()
            ->assertJsonPath('data.notes', 'Invoice wording.');
    }

    public function test_another_company_note_never_leaks_in(): void
    {
        $other = Company::factory()->create();
        PrintTemplate::withoutGlobalScopes()
            ->where('company_id', $other->id)
            ->where('type', 'factura')
            ->update(['notes' => 'Their private note.']);

        $this->issue('factura')->assertCreated()->assertJsonPath('data.notes', null);
    }
}
