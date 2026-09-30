<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProductImage;
use App\Models\Subscription;
use App\Services\ProductImageStore;
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

    public function test_a_large_photo_is_shrunk_to_about_200kb_and_resized(): void
    {
        $upload = $this->busyPhoto('big-photo.jpg', 3000, 2000, 'jpeg');
        $this->assertGreaterThan(1_000_000, $upload->getSize(), 'the test photo should start out big');

        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', ['files' => [$upload]])
            ->assertCreated();

        $image = ProductImage::sole();
        $stored = Storage::disk('local')->get($image->path);

        $this->assertLessThanOrEqual(ProductImageStore::TARGET_BYTES, strlen($stored));
        $this->assertSame(strlen($stored), $image->size_bytes);
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertSame('big-photo', $response->json('data.images.0.name'));

        [$width, $height] = getimagesizefromstring($stored);
        $this->assertLessThanOrEqual(ProductImageStore::MAX_SIDE, max($width, $height));
        // The shape is kept: 3:2 in, 3:2 out.
        $this->assertEqualsWithDelta(1.5, $width / $height, 0.02);
    }

    public function test_a_large_png_is_stored_as_a_small_jpeg(): void
    {
        $upload = $this->busyPhoto('shot.png', 2400, 1600, 'png');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', ['files' => [$upload]])
            ->assertCreated();

        $image = ProductImage::sole();
        $stored = Storage::disk('local')->get($image->path);

        $this->assertSame('image/jpeg', $image->mime);
        $this->assertStringEndsWith('.jpg', $image->path);
        $this->assertLessThanOrEqual(ProductImageStore::TARGET_BYTES, strlen($stored));
    }

    public function test_an_image_that_is_already_small_is_kept_untouched(): void
    {
        $upload = UploadedFile::fake()->image('tiny.png', 300, 200);
        $original = file_get_contents($upload->getRealPath());

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', ['files' => [$upload]])
            ->assertCreated();

        $image = ProductImage::sole();

        $this->assertSame($original, Storage::disk('local')->get($image->path));
        $this->assertSame('image/png', $image->mime);
        $this->assertSame(strlen($original), $image->size_bytes);
    }

    public function test_deleting_removes_the_file_from_storage_as_well_as_the_row(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', ['files' => [$this->realJpeg('gone.jpg')]])
            ->assertCreated();

        $image = ProductImage::sole();
        $path = $image->path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/product-images/{$image->uuid}")
            ->assertOk();

        $this->assertDatabaseCount('product_images', 0);
        Storage::disk('local')->assertMissing($path);
        $this->assertSame([], Storage::disk('local')->allFiles("product-images/{$this->company->id}"));
    }

    public function test_when_the_file_cannot_be_removed_the_row_is_kept_and_nothing_is_orphaned(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/app/product-images', ['files' => [$this->realJpeg('stuck.jpg')]])
            ->assertCreated();

        $image = ProductImage::sole();

        $this->partialMock(ProductImageStore::class, fn ($mock) => $mock->shouldReceive('remove')->andReturn(false));

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/app/product-images/{$image->uuid}")
            ->assertStatus(500);

        // The delete rolled back: the record is still there to try again with.
        $this->assertDatabaseCount('product_images', 1);
        Storage::disk('local')->assertExists($image->path);
    }

    public function test_an_upload_that_cannot_be_recorded_leaves_no_file_behind(): void
    {
        ProductImage::creating(fn () => throw new \RuntimeException('database is down'));

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->owner, 'sanctum')
                ->postJson('/api/app/product-images', ['files' => [$this->realJpeg('lost.jpg')]]);
            $this->fail('the upload should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('database is down', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles('product-images'));
    }

    public function test_the_prune_command_deletes_only_old_files_no_row_points_to(): void
    {
        $disk = Storage::disk('local');
        $kept = ProductImage::factory()->create(['company_id' => $this->company->id, 'path' => 'product-images/1/kept.jpg']);
        $disk->put($kept->path, 'kept');
        $disk->put('product-images/1/orphan-old.jpg', 'old');
        $disk->put('product-images/1/orphan-new.jpg', 'new');
        touch($disk->path($kept->path), time() - 7200);
        touch($disk->path('product-images/1/orphan-old.jpg'), time() - 7200);

        $this->artisan('storage:prune-orphans', ['--dry-run' => true])->assertSuccessful();
        $disk->assertExists('product-images/1/orphan-old.jpg');

        $this->artisan('storage:prune-orphans')->assertSuccessful();

        $disk->assertMissing('product-images/1/orphan-old.jpg');
        $disk->assertExists('product-images/1/orphan-new.jpg');
        $disk->assertExists($kept->path);
    }

    /** A picture full of random colour blocks, which compresses badly, like a real photo does. */
    private function busyPhoto(string $clientName, int $width, int $height, string $format): UploadedFile
    {
        $canvas = imagecreatetruecolor($width, $height);
        mt_srand(7);

        for ($i = 0; $i < 6000; $i++) {
            $x = mt_rand(0, $width);
            $y = mt_rand(0, $height);
            imagefilledrectangle(
                $canvas,
                $x,
                $y,
                $x + mt_rand(8, 90),
                $y + mt_rand(8, 90),
                (int) imagecolorallocate($canvas, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)),
            );
        }

        $path = sys_get_temp_dir().'/'.Str::uuid().'.'.($format === 'png' ? 'png' : 'jpg');
        $format === 'png' ? imagepng($canvas, $path, 1) : imagejpeg($canvas, $path, 98);
        imagedestroy($canvas);

        return new UploadedFile($path, $clientName, $format === 'png' ? 'image/png' : 'image/jpeg', null, true);
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
