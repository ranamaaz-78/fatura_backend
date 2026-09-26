<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProductImage;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductImageFolderTest extends TestCase
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

    public function test_the_folder_lists_only_its_own_images_newest_first(): void
    {
        ProductImage::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'older',
            'created_at' => now()->subDay(),
        ]);
        ProductImage::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'newer',
            'created_at' => now(),
        ]);
        ProductImage::factory()->create(['company_id' => Company::factory()->create()->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/app/product-images')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'newer')
            ->assertJsonPath('data.1.name', 'older');
    }

    public function test_uploading_drops_the_extension_and_stores_a_generated_path(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', [
                'files' => [UploadedFile::fake()->image('stone1.jpg', 40, 40)],
            ])
            ->assertCreated()
            ->assertJsonPath('data.images.0.name', 'stone1')
            ->assertJsonCount(0, 'data.errors');

        $image = ProductImage::first();

        $this->assertSame('stone1', $image->name);
        $this->assertSame('stone1', $image->name_key);
        // The path is built from the company and a uuid, never from the label.
        $this->assertSame("product-images/{$this->company->id}/", Str::beforeLast($image->path, '/').'/');
        $this->assertStringNotContainsString('stone1', $image->path);
        Storage::disk('local')->assertExists($image->path);

        // The response never leaks where the bytes live.
        $this->assertStringNotContainsString($image->path, $response->getContent());
    }

    public function test_a_duplicate_name_fails_only_that_file(): void
    {
        ProductImage::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Stone1',
            'name_key' => 'stone1',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', [
                'files' => [
                    UploadedFile::fake()->image('stone1.jpg', 40, 40),
                    UploadedFile::fake()->image('rock.png', 40, 40),
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(1, 'data.images')
            ->assertJsonPath('data.images.0.name', 'rock')
            ->assertJsonPath('data.errors.0.file', 'stone1.jpg')
            ->assertJsonPath('data.errors.0.message', 'A file named stone1 is already in this folder.');

        $this->assertSame(2, ProductImage::count());
    }

    public function test_renaming_moves_the_label_and_leaves_the_file_alone(): void
    {
        $image = ProductImage::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'stone1',
            'name_key' => 'stone1',
        ]);
        $path = $image->path;

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/product-images/{$image->uuid}", ['name' => 'Marble Slab.jpg'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Marble Slab');

        $image->refresh();

        $this->assertSame('Marble Slab', $image->name);
        $this->assertSame('marble slab', $image->name_key);
        $this->assertSame($path, $image->path);
    }

    public function test_renaming_onto_an_existing_name_is_rejected_case_insensitively(): void
    {
        ProductImage::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'stone1',
            'name_key' => 'stone1',
        ]);
        $other = ProductImage::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'rock',
            'name_key' => 'rock',
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/product-images/{$other->uuid}", ['name' => 'STONE1'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A file named STONE1 is already in this folder.');

        $this->assertSame('rock', $other->fresh()->name);
    }

    public function test_the_name_is_stripped_of_paths_and_control_characters(): void
    {
        $image = ProductImage::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/product-images/{$image->uuid}", ['name' => "../../etc/stone\t1"])
            ->assertOk()
            ->assertJsonPath('data.name', 'etc stone1');

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/app/product-images/{$image->uuid}", ['name' => '..'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Enter a file name.');
    }

    public function test_the_extension_must_match_the_type_found_in_the_bytes(): void
    {
        // Real JPEG bytes, but it claims to be a PNG in both the name and the header.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', ['files' => [$this->realJpeg('stone1.png')]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The file extension does not match the real image type.');

        $this->assertSame(0, ProductImage::count());
    }

    public function test_non_images_and_oversized_files_are_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', ['files' => [UploadedFile::fake()->create('notes.pdf', 10)]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only JPEG, PNG, WebP and GIF images are allowed.');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', [
                'files' => [UploadedFile::fake()->image('huge.jpg')->size(6 * 1024)],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Images must be 5 MB or smaller.');

        $this->assertSame(0, ProductImage::count());
    }

    public function test_the_file_streams_for_the_owner_and_hides_from_everyone_else(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', [
                'files' => [UploadedFile::fake()->image('stone1.jpg', 40, 40)],
            ])
            ->assertCreated();

        $image = ProductImage::first();

        $this->actingAs($this->owner, 'sanctum')
            ->get("/api/app/product-images/{$image->uuid}/file")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        $intruderCompany = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $intruderCompany->id]);
        $intruder = User::factory()->businessAdmin()->create(['company_id' => $intruderCompany->id]);

        $this->actingAs($intruder, 'sanctum')
            ->get("/api/app/product-images/{$image->uuid}/file")
            ->assertNotFound();

        // actingAs sticks for the whole test, so drop it before the guest check.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/app/product-images/{$image->uuid}/file")->assertUnauthorized();
    }

    public function test_deleting_removes_the_row_and_the_file(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', [
                'files' => [UploadedFile::fake()->image('stone1.jpg', 40, 40)],
            ])
            ->assertCreated();

        $image = ProductImage::first();
        Storage::disk('local')->assertExists($image->path);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/product-images/{$image->uuid}")
            ->assertOk();

        $this->assertSame(0, ProductImage::count());
        Storage::disk('local')->assertMissing($image->path);
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
