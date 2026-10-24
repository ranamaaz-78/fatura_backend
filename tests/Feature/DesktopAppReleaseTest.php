<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DesktopRelease;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DesktopAppReleaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /** A pretend installer: starts with "MZ" like every Windows program, a little over the 1 MB minimum. */
    private function installer(string $fill = 'a'): string
    {
        return 'MZ'.str_repeat($fill, 1024 * 1024 + 10);
    }

    /** Uploads the bytes in three pieces and publishes them, as the admin panel does. */
    private function publish(User $admin, string $bytes, string $version = '1.1.0')
    {
        $start = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/desktop-app/uploads', [
                'file_name' => "YK Digital Solutions Setup {$version}.exe",
                'size_bytes' => strlen($bytes),
                'version' => $version,
                'notes' => 'New look',
            ])
            ->assertCreated();

        $id = $start->json('data.upload_id');
        $size = (int) ceil(strlen($bytes) / 3);

        foreach (str_split($bytes, $size) as $index => $piece) {
            $this->post("/api/admin/desktop-app/uploads/{$id}/chunks", [
                'index' => $index,
                'chunk' => UploadedFile::fake()->createWithContent('chunk', $piece),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        return $this->postJson("/api/admin/desktop-app/uploads/{$id}/complete");
    }

    public function test_the_admin_publishes_the_installer_and_the_website_offers_it(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $bytes = $this->installer();

        $this->publish($admin, $bytes)
            ->assertOk()
            ->assertJsonPath('data.release.version', '1.1.0')
            ->assertJsonPath('data.release.size_bytes', strlen($bytes))
            ->assertJsonPath('data.release.sha256', hash('sha256', $bytes));

        $this->getJson('/api/public/desktop-app')
            ->assertOk()
            ->assertJsonPath('data.version', '1.1.0')
            ->assertJsonPath('data.file_name', 'YK-Digital-Solutions-Setup-1.1.0.exe')
            ->assertJsonPath('data.download_url', '/public/desktop-app/download');

        $download = $this->get('/api/public/desktop-app/download')->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('YK-Digital-Solutions-Setup-1.1.0.exe', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame($bytes, file_get_contents($download->baseResponse->getFile()->getPathname()));
    }

    public function test_nothing_is_offered_until_an_installer_is_uploaded(): void
    {
        $this->getJson('/api/public/desktop-app')->assertOk()->assertJsonPath('data', null);
        $this->get('/api/public/desktop-app/download')->assertNotFound();
        $this->get('/api/public/desktop-app/updates/latest.yml')->assertNotFound();
    }

    public function test_the_installed_app_finds_the_new_version_in_the_update_feed(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $bytes = $this->installer();
        $this->publish($admin, $bytes, '1.2.0')->assertOk();

        $sha512 = base64_encode(hash('sha512', $bytes, true));
        $manifest = $this->get('/api/public/desktop-app/updates/latest.yml')->assertOk();

        $this->assertStringContainsString('no-store', (string) $manifest->headers->get('Cache-Control'));
        $body = $manifest->getContent();
        $this->assertStringContainsString("version: 1.2.0\n", $body);
        $this->assertStringContainsString('  - url: "YK-Digital-Solutions-Setup-1.2.0.exe"', $body);
        $this->assertStringContainsString("sha512: {$sha512}\n", $body);
        $this->assertStringContainsString('size: '.strlen($bytes), $body);
        $this->assertStringContainsString('releaseNotes: "New look"', $body);

        // The updater downloads the file by the name in the manifest; any other name is not there.
        $file = $this->get('/api/public/desktop-app/updates/YK-Digital-Solutions-Setup-1.2.0.exe')->assertOk();
        $this->assertSame($bytes, file_get_contents($file->baseResponse->getFile()->getPathname()));
        $this->get('/api/public/desktop-app/updates/YK-Digital-Solutions-Setup-1.1.0.exe')->assertNotFound();
        $this->get('/api/public/desktop-app/updates/YK-Digital-Solutions-Setup-1.2.0.exe.blockmap')->assertNotFound();
    }

    public function test_only_the_platform_admin_may_upload(): void
    {
        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $owner = User::factory()->businessAdmin()->create(['company_id' => $company->id]);

        $this->postJson('/api/admin/desktop-app/uploads', [])->assertUnauthorized();
        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/desktop-app')->assertForbidden();
        $this->actingAs($owner, 'sanctum')->deleteJson('/api/admin/desktop-app')->assertForbidden();
    }

    public function test_pieces_out_of_order_are_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $bytes = $this->installer();

        $id = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/desktop-app/uploads', ['file_name' => 'setup.exe', 'size_bytes' => strlen($bytes), 'version' => '1.0.0'])
            ->json('data.upload_id');

        $this->post("/api/admin/desktop-app/uploads/{$id}/chunks", [
            'index' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', substr($bytes, 0, 100)),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('index');
    }

    public function test_a_file_that_is_not_a_windows_program_is_refused(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->publish($admin, 'PK'.str_repeat('z', 1024 * 1024 + 10))
            ->assertStatus(422)
            ->assertJsonValidationErrors('upload');

        $this->assertSame(0, DesktopRelease::count());
    }

    public function test_the_start_checks_the_file_name_size_and_version(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/desktop-app/uploads', ['file_name' => 'setup.zip', 'size_bytes' => 5 * 1024 * 1024, 'version' => '1.0.0'])
            ->assertStatus(422)->assertJsonValidationErrors('file_name');

        $this->postJson('/api/admin/desktop-app/uploads', ['file_name' => 'setup.exe', 'size_bytes' => 1000, 'version' => '1.0.0'])
            ->assertStatus(422)->assertJsonValidationErrors('size_bytes');

        $this->postJson('/api/admin/desktop-app/uploads', ['file_name' => 'setup.exe', 'size_bytes' => 5 * 1024 * 1024, 'version' => 'latest'])
            ->assertStatus(422)->assertJsonValidationErrors('version');
    }

    public function test_a_new_version_replaces_the_old_one(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->publish($admin, $this->installer('a'), '1.0.0')->assertOk();
        $this->publish($admin, $this->installer('b'), '1.1.0')->assertOk();

        $this->assertSame(1, DesktopRelease::count());
        $this->assertSame('1.1.0', DesktopRelease::current()->version);
        Storage::disk('local')->assertMissing('desktop/YK-Digital-Solutions-Setup-1.0.0.exe');
        Storage::disk('local')->assertExists('desktop/YK-Digital-Solutions-Setup-1.1.0.exe');
    }

    public function test_the_admin_takes_the_installer_off_the_website(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->publish($admin, $this->installer())->assertOk();

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/desktop-app')->assertOk();

        $this->getJson('/api/public/desktop-app')->assertJsonPath('data', null);
        Storage::disk('local')->assertMissing('desktop/YK-Digital-Solutions-Setup-1.1.0.exe');
    }
}
