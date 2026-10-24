<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DesktopRelease;
use App\Services\DesktopReleaseStore;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Windows app installer the website offers. The platform admin uploads a new version here (in pieces, see
 * DesktopReleaseStore) or takes it off the website.
 */
class DesktopAppController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DesktopReleaseStore $store) {}

    public function show(): JsonResponse
    {
        return $this->success([
            'release' => DesktopRelease::current()?->toPublicArray(),
            'chunk_size' => $this->store->chunkSize(),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:200'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->success(
            $this->store->start($data['file_name'], (int) $data['size_bytes'], $data['version'], $data['notes'] ?? null),
            '',
            201,
        );
    }

    public function chunk(Request $request, string $upload): JsonResponse
    {
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'chunk' => ['required', 'file'],
        ]);

        return $this->success($this->store->append($upload, (int) $data['index'], $request->file('chunk')));
    }

    public function complete(Request $request, string $upload): JsonResponse
    {
        $release = $this->store->complete($upload, $request->user());

        return $this->success(['release' => $release->toPublicArray()], __('The Windows app is on the website.'));
    }

    public function destroy(): JsonResponse
    {
        $this->store->remove();

        return $this->success(['release' => null], __('The Windows app was taken off the website.'));
    }
}
