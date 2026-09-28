<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PrintTemplate;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrintTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $this->company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $this->company->id]);
    }

    public function test_a_new_company_starts_with_four_printables(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/print-templates')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonCount(4, 'data.templates')
            ->assertJsonPath('data.templates.0.type', 'factura')
            ->assertJsonPath('data.templates.0.primary_color', '#004ac6')
            ->assertJsonPath('data.templates.0.font_key', 'geist')
            ->assertJsonPath('data.templates.1.type', 'albaran')
            ->assertJsonPath('data.templates.1.show_signature', true)
            ->assertJsonPath('data.templates.1.show_logo', false)
            ->assertJsonPath('data.templates.2.type', 'quotation')
            ->assertJsonPath('data.templates.3.type', 'proforma');
    }

    public function test_saving_one_type_does_not_change_the_others(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/print-templates/factura', [
                'primary_color' => '#be123c',
                'font_key' => 'source_serif',
                'footer_notes' => 'Pay within 7 days.',
                'show_logo' => false,
                'show_signature' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.templates.0.primary_color', '#be123c')
            ->assertJsonPath('data.templates.0.font_key', 'source_serif')
            ->assertJsonPath('data.templates.0.footer_notes', 'Pay within 7 days.')
            ->assertJsonPath('data.templates.0.show_logo', false)
            ->assertJsonPath('data.templates.1.primary_color', '#004ac6')
            ->assertJsonPath('data.templates.2.font_key', 'geist');
    }

    public function test_invalid_color_font_and_type_are_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/print-templates/factura', [
                'primary_color' => 'blue',
                'font_key' => 'comic_sans',
                'footer_notes' => '',
                'show_logo' => true,
                'show_signature' => false,
            ])
            ->assertStatus(422);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/print-templates/abono', [
                'primary_color' => '#004ac6',
                'font_key' => 'geist',
                'footer_notes' => '',
                'show_logo' => true,
                'show_signature' => false,
            ])
            ->assertNotFound();
    }

    public function test_copy_writes_the_source_onto_the_other_types(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/print-templates/factura', [
                'primary_color' => '#047857',
                'font_key' => 'dm_sans',
                'footer_notes' => 'Shared terms.',
                'show_logo' => true,
                'show_signature' => true,
            ])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/print-templates/factura/copy', [
                'types' => ['quotation', 'proforma'],
            ])
            ->assertOk()
            ->assertJsonPath('data.templates.2.primary_color', '#047857')
            ->assertJsonPath('data.templates.2.font_key', 'dm_sans')
            ->assertJsonPath('data.templates.3.footer_notes', 'Shared terms.')
            ->assertJsonPath('data.templates.1.primary_color', '#004ac6');
    }

    public function test_albaran_cannot_turn_on_a_logo(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/print-templates/albaran', [
                'primary_color' => '#111111',
                'font_key' => 'merriweather',
                'footer_notes' => 'Changed.',
                'show_logo' => true,
                'show_signature' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.templates.1.font_key', 'merriweather')
            ->assertJsonPath('data.templates.1.show_logo', false);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/print-templates/factura', [
                'primary_color' => '#047857',
                'font_key' => 'playfair_display',
                'footer_notes' => 'Shared.',
                'show_logo' => true,
                'show_signature' => false,
            ])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/print-templates/factura/copy', [
                'types' => ['albaran'],
            ])
            ->assertOk()
            ->assertJsonPath('data.templates.1.primary_color', '#047857')
            ->assertJsonPath('data.templates.1.font_key', 'playfair_display')
            ->assertJsonPath('data.templates.1.show_logo', false);
    }

    public function test_reset_restores_the_fatura_defaults(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/print-templates/albaran', [
                'primary_color' => '#111111',
                'font_key' => 'dm_sans',
                'footer_notes' => 'Changed.',
                'show_logo' => false,
                'show_signature' => false,
            ])
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/print-templates/albaran/reset')
            ->assertOk()
            ->assertJsonPath('data.templates.1.primary_color', '#004ac6')
            ->assertJsonPath('data.templates.1.font_key', 'geist')
            ->assertJsonPath('data.templates.1.show_logo', false)
            ->assertJsonPath('data.templates.1.show_signature', true)
            ->assertJsonPath('data.templates.1.footer_notes', PrintTemplate::defaultFor('albaran')['footer_notes']);
    }

    public function test_another_company_cannot_see_or_change_these_printables(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->putJson('/api/app/print-templates/factura', [
                'primary_color' => '#be123c',
                'font_key' => 'geist',
                'footer_notes' => 'Secret',
                'show_logo' => true,
                'show_signature' => false,
            ])
            ->assertOk();

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/app/print-templates')
            ->assertOk()
            ->assertJsonPath('data.templates.0.primary_color', '#004ac6')
            ->assertJsonPath('data.templates.0.footer_notes', PrintTemplate::defaultFor('factura')['footer_notes']);
    }

    public function test_the_logo_uploads_streams_for_the_owner_and_hides_from_everyone_else(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->post('/api/app/company/logo', [
                'file' => UploadedFile::fake()->image('mark.png', 80, 80),
            ])
            ->assertOk()
            ->assertJsonPath('data.logo_url', '/app/company/logo');

        $this->company->refresh();
        $this->assertNotNull($this->company->logo_path);
        Storage::disk('local')->assertExists($this->company->logo_path);

        $this->actingAs($this->owner, 'sanctum')
            ->get('/api/app/company/logo')
            ->assertOk()
            ->assertHeader('content-type', 'image/png');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/print-templates')
            ->assertJsonPath('data.logo_url', '/app/company/logo');

        $other = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $other->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $other->id]);

        $this->actingAs($intruder, 'sanctum')
            ->get('/api/app/company/logo')
            ->assertNotFound();

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson('/api/app/company/logo')
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('local')->assertMissing($this->company->logo_path);
        $this->assertNull($this->company->fresh()->logo_path);
    }

    public function test_non_images_and_mismatched_extensions_are_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->post('/api/app/company/logo', [
                'file' => UploadedFile::fake()->create('notes.pdf', 10),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only JPEG, PNG and WebP images are allowed.');

        $this->actingAs($this->owner, 'sanctum')
            ->post('/api/app/company/logo', [
                'file' => $this->realJpeg('mark.png'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The file extension does not match the real image type.');

        $this->assertNull($this->company->fresh()->logo_path);
    }

    private function realJpeg(string $clientName): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.Str::uuid().'.jpg';
        $canvas = imagecreatetruecolor(20, 20);
        imagejpeg($canvas, $path);
        imagedestroy($canvas);

        return new UploadedFile($path, $clientName, 'image/png', null, true);
    }
}
