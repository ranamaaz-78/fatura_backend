<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageStore
{
    public const DISK = 'local';

    /** The largest upload we will accept. What is stored is far smaller, see TARGET_BYTES. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** A stored image is squeezed to about this size, however big it arrived. */
    public const TARGET_BYTES = 200 * 1000;

    /** No stored image is longer than this on either side. */
    public const MAX_SIDE = 1280;

    /** Refuse pictures that would need too much memory to open (width x height). */
    private const MAX_PIXELS = 40_000_000;

    /** Content type we accept, mapped to the extensions it may arrive with. */
    private const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
    ];

    /** Scales tried in turn, each with a run of JPEG qualities, until the file is small enough. */
    private const SCALES = [1.0, 0.85, 0.7, 0.55, 0.4, 0.3];

    private const QUALITIES = [85, 78, 70, 62, 54, 46, 38];

    /** The reason this upload cannot be stored, or null when it is fine. */
    public function reject(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return __('The upload did not finish.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return __('Images must be 5 MB or smaller.');
        }

        // Read the type off the bytes; the browser header is not trusted.
        $mime = $file->getMimeType();

        if ($mime === null || ! array_key_exists($mime, self::ALLOWED)) {
            return __('Only JPEG, PNG, WebP and GIF images are allowed.');
        }

        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED[$mime], true)) {
            return __('The file extension does not match the real image type.');
        }

        $size = @getimagesize($file->getRealPath());

        if ($size !== false && $size[0] * $size[1] > self::MAX_PIXELS) {
            return __('That image is too large in pixels. Use one under 40 megapixels.');
        }

        return null;
    }

    /**
     * Shrinks the picture to a web-sized JPEG of about TARGET_BYTES, writes it to the
     * private disk under a generated path, and reports what was actually stored.
     * The path is never derived from the label, so renaming leaves it alone.
     *
     * @return array{path: string, mime: string, size: int}
     */
    public function put(UploadedFile $file, int $companyId): array
    {
        $image = $this->optimise($file);
        $path = "product-images/{$companyId}/".Str::uuid().'.'.$image['extension'];

        Storage::disk(self::DISK)->put($path, $image['bytes']);

        return ['path' => $path, 'mime' => $image['mime'], 'size' => strlen($image['bytes'])];
    }

    /** True only when the file is really gone. A file that will not delete is reported, never ignored. */
    public function remove(string $path): bool
    {
        $disk = Storage::disk(self::DISK);

        if ($disk->exists($path)) {
            $disk->delete($path);
        }

        return ! $disk->exists($path);
    }

    /**
     * @return array{bytes: string, mime: string, extension: string}
     */
    private function optimise(UploadedFile $file): array
    {
        $original = (string) file_get_contents($file->getRealPath());
        $mime = (string) $file->getMimeType();
        $kept = ['bytes' => $original, 'mime' => $mime, 'extension' => self::ALLOWED[$mime][0]];

        $size = @getimagesizefromstring($original);

        // Already small and no bigger than we allow: keep it exactly as it came.
        if ($size !== false && strlen($original) <= self::TARGET_BYTES && max($size[0], $size[1]) <= self::MAX_SIDE) {
            return $kept;
        }

        $source = $this->open($original);

        if ($source === null) {
            return $kept;
        }

        $source = $this->upright($source, $original, $mime);
        $width = imagesx($source);
        $height = imagesy($source);
        $fit = min(1.0, self::MAX_SIDE / max($width, $height));

        $best = null;

        foreach (self::SCALES as $scale) {
            $canvas = $this->flatCopy(
                $source,
                max(1, (int) round($width * $fit * $scale)),
                max(1, (int) round($height * $fit * $scale)),
            );

            foreach (self::QUALITIES as $quality) {
                $bytes = $this->jpeg($canvas, $quality);

                if ($best === null || strlen($bytes) < strlen($best)) {
                    $best = $bytes;
                }

                if (strlen($bytes) <= self::TARGET_BYTES) {
                    return ['bytes' => $bytes, 'mime' => 'image/jpeg', 'extension' => 'jpg'];
                }
            }
        }

        // Nothing reached the target (extreme noise): the smallest attempt is still far below the original.
        return ['bytes' => $best ?? $original, 'mime' => 'image/jpeg', 'extension' => 'jpg'];
    }

    private function open(string $bytes): ?\GdImage
    {
        // Opening a big picture is memory hungry; give it room without lowering an existing higher limit.
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && $this->toBytes((string) $limit) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }

        $image = @imagecreatefromstring($bytes);

        return $image === false ? null : $image;
    }

    /** Applies the camera's rotation so a phone photo is not stored on its side. */
    private function upright(\GdImage $image, string $bytes, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }

    /** A resized copy on a white background, so transparency does not turn black in a JPEG. */
    private function flatCopy(\GdImage $source, int $width, int $height): \GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $canvas;
    }

    private function jpeg(\GdImage $image, int $quality): string
    {
        imageinterlace($image, true);

        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private function toBytes(string $value): int
    {
        $number = (int) $value;

        return match (strtolower(substr(trim($value), -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
