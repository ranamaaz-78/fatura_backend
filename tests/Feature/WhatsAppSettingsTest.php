<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppSettingsTest extends TestCase
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

    public function test_the_message_wording_is_saved_and_read_back(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/whatsapp/settings')
            ->assertOk()
            ->assertJsonPath('data.message_template', null);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/whatsapp/settings', ['message_template' => 'Hi {customer_name}, your {document_type} is ready.'])
            ->assertOk()
            ->assertJsonPath('data.message_template', 'Hi {customer_name}, your {document_type} is ready.');

        $this->assertSame('Hi {customer_name}, your {document_type} is ready.', $this->company->fresh()->whatsapp_message_template);

        // An empty wording goes back to the standard message.
        $this->actingAs($this->owner, 'sanctum')
            ->patchJson('/api/app/whatsapp/settings', ['message_template' => ''])
            ->assertOk()
            ->assertJsonPath('data.message_template', null);
    }

    public function test_the_server_no_longer_links_or_sends_whatsapp_sessions(): void
    {
        foreach (['status', 'init', 'logout', 'send-document', 'test'] as $path) {
            $this->actingAs($this->owner, 'sanctum')->postJson("/api/app/whatsapp/{$path}")->assertStatus(404);
        }
    }
}
