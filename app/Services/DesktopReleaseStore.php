<?php

namespace App\Services;

use App\Models\DesktopRelease;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Takes the Windows app installer from the admin panel in small pieces and publishes it. An installer is around
 * 90 MB, far over the upload limit of most hosting, so the browser sends it in chunks no bigger than the server
 * accepts; they are appended in order and checked once the last one arrives.
 */
class DesktopReleaseStore
{
    public const DISK = 'local';

    public const MIN_BYTES = 1024 * 1024;

    public const MAX_BYTES = 400 * 1024 * 1024;

    private const UPLOADS = 'desktop-uploads';

    private const RELEASES = 'desktop';

    /** An upload nobody finished is cleared after this long. */
    private const STALE_SECONDS = 24 * 3600;

    /**
     * How big each piece may be: a little under what this server's PHP accepts in one request, between 512 KB and
     * 8 MB, so it works on any hosting without changing its settings.
     */
    public function chunkSize(): int
    {
        $limits = array_filter([
            $this->iniBytes('upload_max_filesize'),
            $this->iniBytes('post_max_size'),
        ]);
        $limit = $limits === [] ? 8 * 1024 * 1024 : min($limits);

        return (int) max(512 * 1024, min(8 * 1024 * 1024, floor($limit * 0.8)));
    }

    /** @return array{upload_id: string, chunk_size: int} */
    public function start(string $fileName, int $sizeBytes, string $version, ?string $notes): array
    {
        if (! Str::endsWith(mb_strtolower($fileName), '.exe')) {
            throw ValidationException::withMessages(['file_name' => __('Upload the Windows installer (.exe).')]);
        }

        if ($sizeBytes < self::MIN_BYTES || $sizeBytes > self::MAX_BYTES) {
            throw ValidationException::withMessages(['size_bytes' => __('The installer must be between 1 MB and 400 MB.')]);
        }

        $this->clearStale();

        $id = (string) Str::uuid();
        $this->disk()->put($this->partPath($id), '');
        $this->writeMeta($id, [
            'file_name' => $fileName,
            'size_bytes' => $sizeBytes,
            'version' => $version,
            'notes' => $notes,
            'received' => 0,
            'next_index' => 0,
        ]);

        return ['upload_id' => $id, 'chunk_size' => $this->chunkSize()];
    }

    /** Adds one piece. Pieces must come in order, and never past the size announced at the start. */
    public function append(string $id, int $index, UploadedFile $chunk): array
    {
        $meta = $this->meta($id);

        if (! $chunk->isValid()) {
            throw ValidationException::withMessages(['chunk' => __('The upload did not finish.')]);
        }

        if ($index !== $meta['next_index']) {
            throw ValidationException::withMessages(['index' => __('A piece of the installer arrived out of order. Start the upload again.')]);
        }

        $size = (int) $chunk->getSize();

        if ($meta['received'] + $size > $meta['size_bytes']) {
            throw ValidationException::withMessages(['chunk' => __('The installer is bigger than announced. Start the upload again.')]);
        }

        $target = fopen($this->disk()->path($this->partPath($id)), 'ab');
        $source = fopen($chunk->getRealPath(), 'rb');
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        $meta['received'] += $size;
        $meta['next_index'] = $index + 1;
        $this->writeMeta($id, $meta);

        return ['received' => $meta['received'], 'size_bytes' => $meta['size_bytes']];
    }

    /** Checks the whole file and publishes it in place of the release on offer. */
    public function complete(string $id, ?User $user): DesktopRelease
    {
        $meta = $this->meta($id);
        $disk = $this->disk();
        $part = $this->partPath($id);

        if ($meta['received'] !== $meta['size_bytes'] || $disk->size($part) !== $meta['size_bytes']) {
            throw ValidationException::withMessages(['upload' => __('The upload did not finish.')]);
        }

        // Every Windows program starts with "MZ"; anything else is not an installer.
        $handle = fopen($disk->path($part), 'rb');
        $magic = fread($handle, 2);
        fclose($handle);

        if ($magic !== 'MZ') {
            $this->discard($id);
            throw ValidationException::withMessages(['upload' => __('This file is not a Windows program.')]);
        }

        $sha256 = hash_file('sha256', $disk->path($part));
        // The installed app's updater checks the download against this one (base64, as electron-updater expects).
        $sha512 = base64_encode(hash_file('sha512', $disk->path($part), true));
        $fileName = 'YK-Digital-Solutions-Setup-'.$meta['version'].'.exe';
        $path = self::RELEASES.'/'.$fileName;

        $disk->delete($path);
        $disk->move($part, $path);
        $disk->delete($this->metaPath($id));

        return DB::transaction(function () use ($meta, $fileName, $path, $sha256, $sha512, $user, $disk) {
            $release = DesktopRelease::create([
                'version' => $meta['version'],
                'notes' => $meta['notes'],
                'file_path' => $path,
                'file_name' => $fileName,
                'size_bytes' => $meta['size_bytes'],
                'sha256' => $sha256,
                'sha512' => $sha512,
                'uploaded_by' => $user?->id,
            ]);

            // Only one installer is kept: the older ones go, with their files (unless it is the same file name).
            foreach (DesktopRelease::olderThan($release)->get() as $old) {
                if ($old->file_path !== $path) {
                    $disk->delete($old->file_path);
                }
                $old->delete();
            }

            return $release;
        });
    }

    /** Takes the installer off the website. */
    public function remove(): void
    {
        foreach (DesktopRelease::all() as $release) {
            $this->disk()->delete($release->file_path);
            $release->delete();
        }
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    private function meta(string $id): array
    {
        $raw = $this->disk()->get($this->metaPath($id));
        $meta = $raw === null ? null : json_decode($raw, true);

        if (! is_array($meta)) {
            throw ValidationException::withMessages(['upload' => __('This upload has expired. Start it again.')]);
        }

        return $meta;
    }

    private function writeMeta(string $id, array $meta): void
    {
        $this->disk()->put($this->metaPath($id), json_encode($meta));
    }

    private function discard(string $id): void
    {
        $this->disk()->delete([$this->partPath($id), $this->metaPath($id)]);
    }

    private function clearStale(): void
    {
        $disk = $this->disk();

        foreach ($disk->files(self::UPLOADS) as $file) {
            if (time() - $disk->lastModified($file) > self::STALE_SECONDS) {
                $disk->delete($file);
            }
        }
    }

    private function partPath(string $id): string
    {
        return self::UPLOADS.'/'.$id.'.part';
    }

    private function metaPath(string $id): string
    {
        return self::UPLOADS.'/'.$id.'.json';
    }

    private function iniBytes(string $key): int
    {
        $value = ini_get($key);

        return $value === false || $value === '' ? 0 : (int) ini_parse_quantity($value);
    }
}
