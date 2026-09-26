<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageStore
{
    public const DISK = 'local';

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Content type we accept, mapped to the extensions it may arrive with. */
    private const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
    ];

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

        return null;
    }

    /**
     * Writes the file to the private disk under a generated path and returns it.
     * The path is never derived from the label, so renaming leaves it alone.
     */
    public function put(UploadedFile $file, int $companyId): string
    {
        $name = Str::uuid().'.'.$this->extension($file);

        return Storage::disk(self::DISK)->putFileAs("product-images/{$companyId}", $file, $name);
    }

    public function remove(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }

    public function mime(UploadedFile $file): string
    {
        return (string) $file->getMimeType();
    }

    private function extension(UploadedFile $file): string
    {
        return self::ALLOWED[$this->mime($file)][0];
    }
}
