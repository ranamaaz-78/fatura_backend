<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProductImage;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Uploads are checked on the server by what the bytes are, not by the name or the browser's word; are limited in
 * size; are stored under a generated name outside the web root; and are sent back in a way that can never run.
 * Files are real temporary files (not UploadedFile::fake()), because fake files report their type from the name.
 */
class UploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create();
        Subscription::factory()->create(['company_id' => $company->id]);
        $this->owner = User::factory()->businessAdmin()->create(['company_id' => $company->id]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function logo(string $name, string $bytes)
    {
        return $this->actingAs($this->owner, 'sanctum')->post('/api/app/company/logo', ['file' => $this->upload($name, $bytes)], ['Accept' => 'application/json']);
    }

    private function image(string $name, string $bytes)
    {
        return $this->actingAs($this->owner, 'sanctum')->post('/api/app/product-images', ['files' => [$this->upload($name, $bytes)]], ['Accept' => 'application/json']);
    }

    /** @return array<string, array{string, string}> */
    private function forbidden(): array
    {
        return [
            'a PHP script named .jpg' => ['photo.jpg', "<?php echo 'not an image'; ?>"],
            'a PHP script' => ['script.php', "<?php echo 'not an image'; ?>"],
            'an SVG with a script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'an HTML page named .png' => ['page.png', '<html><body><script>alert(1)</script></body></html>'],
            'a real PNG named .php' => ['image.php', $this->png()],
            'a real PNG named .html' => ['image.html', $this->png()],
            'PNG magic bytes with nothing behind' => ['fake.png', "\x89PNG\r\n\x1a\n".str_repeat('x', 200)],
            'a file over 5 MB' => ['big.png', $this->png().str_repeat("\0", 5 * 1024 * 1024 + 1)],
        ];
    }

    public function test_anything_that_is_not_a_real_image_is_refused(): void
    {
        $logoBefore = $this->owner->company->fresh()->logo_path;

        foreach ($this->forbidden() as $label => [$name, $bytes]) {
            $this->assertSame(422, $this->logo($name, $bytes)->baseResponse->getStatusCode(), "Logo accepted {$label}.");
            $this->assertSame(422, $this->image($name, $bytes)->baseResponse->getStatusCode(), "Product images accepted {$label}.");
        }

        $this->assertSame($logoBefore, $this->owner->company->fresh()->logo_path);
        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_a_real_image_is_stored_under_a_generated_name_whatever_it_was_called(): void
    {
        $this->logo('../../public/index.php.png', $this->png())->assertOk();
        $this->image('../../public/index.php.png', $this->png())->assertCreated();

        $logo = $this->owner->company->fresh()->logo_path;
        $this->assertMatchesRegularExpression('#^company-logos/\d+/[0-9a-f-]{36}\.png$#', $logo);

        $image = ProductImage::query()->firstOrFail();
        $this->assertMatchesRegularExpression('#^product-images/\d+/[0-9a-f-]{36}\.(png|jpg)$#', $image->path);
        // The label keeps no path: the slashes are gone, so it can never point at another folder.
        $this->assertStringNotContainsString('/', $image->name);
        $this->assertStringNotContainsString(chr(92), $image->name); // backslash

        // Kept in private storage, outside the web root.
        $slashes = fn (string $path) => str_replace(chr(92), '/', $path);
        $this->assertStringStartsWith($slashes(storage_path('app/private')), $slashes(\Storage::disk('local')->path($logo)));
        $this->assertFileDoesNotExist(public_path($logo));
    }

    public function test_stored_files_are_sent_back_as_pictures_that_cannot_run(): void
    {
        // A real PNG with a script tacked on the end: accepted as the picture it is, but never run.
        $this->image('evil.php.png', $this->png().'<script>alert(1)</script>')->assertCreated();
        $this->logo('logo.png', $this->png())->assertOk();
        $image = ProductImage::query()->firstOrFail();

        foreach (["/api/app/product-images/{$image->uuid}/file", '/api/app/company/logo'] as $url) {
            $response = $this->actingAs($this->owner, 'sanctum')->get($url)->assertOk();

            $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'), $url);
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $url);
            $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'), $url);
        }

        // Saved from the browser, it keeps the real extension: never "evil.php".
        $disposition = $this->actingAs($this->owner, 'sanctum')->get("/api/app/product-images/{$image->uuid}/file")->headers->get('Content-Disposition');
        $this->assertMatchesRegularExpression('/filename="?evil\.php\.(png|jpg)"?/', (string) $disposition);
    }

    public function test_no_url_serves_files_straight_from_storage(): void
    {
        $this->logo('logo.png', $this->png())->assertOk();
        $path = $this->owner->company->fresh()->logo_path;

        $this->get('/storage/'.$path)->assertNotFound();
        $this->get('/'.$path)->assertNotFound();
    }
}
