<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CompanyLogoStore
{
    public const DISK = 'local';

    public const MAX_BYTES = 5 * 1024 * 1024;

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

        $mime = $file->getMimeType();

        if ($mime === null || ! array_key_exists($mime, self::ALLOWED)) {
            return __('Only JPEG, PNG and WebP images are allowed.');
        }

        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED[$mime], true)) {
            return __('The file extension does not match the real image type.');
        }

        return null;
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
