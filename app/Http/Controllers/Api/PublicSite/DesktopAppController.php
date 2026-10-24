<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\DesktopRelease;
use App\Services\DesktopReleaseStore;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Windows app on the public website ("Download for Windows"), and the feed the installed app updates itself from.
 */
class DesktopAppController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DesktopReleaseStore $store) {}

    /** The installer on offer, or null when none has been uploaded yet. */
    public function show(): JsonResponse
    {
        return $this->success(DesktopRelease::current()?->toPublicArray());
    }

    /** Streams the installer as a download (resumable: the response answers range requests). */
    public function download(): BinaryFileResponse
    {
        return $this->installer(DesktopRelease::current(), 'public, max-age=300');
    }

    /**
     * What the installed app reads to find out about a new version. Never cached, so an upload shows up straight away.
     */
    public function manifest(): Response
    {
        $release = DesktopRelease::current();
        abort_unless($release !== null && $release->sha512 !== null, 404);

        return response($release->toUpdateManifest(), 200, [
            'Content-Type' => 'text/yaml; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /** The installer under its own name, which is where the manifest points the updater. */
    public function updateFile(string $file): BinaryFileResponse
    {
        $release = DesktopRelease::current();
        abort_unless($release !== null && $file === $release->file_name, 404);

        return $this->installer($release, 'no-cache');
    }

    private function installer(?DesktopRelease $release, string $cache): BinaryFileResponse
    {
        abort_unless($release !== null && $this->store->disk()->exists($release->file_path), 404);

        return response()->download(
            $this->store->disk()->path($release->file_path),
            $release->file_name,
            [
                'Content-Type' => 'application/vnd.microsoft.portable-executable',
                'Cache-Control' => $cache,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
