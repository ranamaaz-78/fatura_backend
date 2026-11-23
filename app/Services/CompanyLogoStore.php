<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CompanyLogoStore
{
    public const DISK = 'local';

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Same ceiling as product images: enough for any logo, too small for a "decompression bomb". */
    private const MAX_PIXELS = 40_000_000;

    private const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public function reject(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return __('The upload did not finish.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            return __('Images must be 5 MB or smaller.');
        }

        // Read the type off the bytes; the browser header is not trusted. A file that cannot be read (removed by
        // an antivirus, say) is a failed upload, not a server error.
        try {
            $mime = $file->getMimeType();
        } catch (\Throwable) {
            return __('The upload did not finish.');
        }

        if ($mime === null || ! array_key_exists($mime, self::ALLOWED)) {
            return __('Only JPEG, PNG and WebP images are allowed.');
        }

        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED[$mime], true)) {
            return __('The file extension does not match the real image type.');
        }

        // The first bytes can be faked; the image header must also read as a picture of that type.
        $size = @getimagesize($file->getRealPath());

        if ($size === false || ($size['mime'] ?? null) !== $mime) {
            return __('This file is not a valid image.');
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            return __('That image is too large in pixels. Use one under 40 megapixels.');
        }

        return null;
    }

    /** Headers for sending a stored image back: shown as a picture only, never run as a page or script. */
    public static function safeHeaders(string $mime): array
    {
        return [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=600',
        ];
    }

    public function put(UploadedFile $file, int $companyId, ?string $previous = null): string
    {
        if ($previous) {
            $this->remove($previous);
        }

        $name = Str::uuid().'.'.$this->extension($file);

        return Storage::disk(self::DISK)->putFileAs("company-logos/{$companyId}", $file, $name);
    }

    public function remove(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    private function extension(UploadedFile $file): string
    {
        return self::ALLOWED[(string) $file->getMimeType()][0];
    }
}
