<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductImageResource;
use App\Models\ProductImage;
use App\Services\ProductImageStore;
use App\Support\ImageName;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductImageController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductImageStore $store) {}

    public function index(): JsonResponse
    {
        $images = ProductImage::newestFirst()->get();

        return $this->success(ProductImageResource::collection($images));
    }

    /**
     * Saves every file it can. One bad file does not hold up the rest, so the
     * per-file reasons travel back next to the images that made it.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:50'],
            'files.*' => ['required', 'file'],
        ]);

        $companyId = (int) $request->user()->company_id;
        $saved = [];
        $failed = [];
        $takenKeys = [];

        /** @var UploadedFile $file */
        foreach ($request->file('files') as $file) {
            $label = $file->getClientOriginalName();

            if ($reason = $this->store->reject($file)) {
                $failed[] = ['file' => $label, 'message' => $reason];

                continue;
            }

            $name = ImageName::clean($label);

            if ($name === null) {
                $failed[] = ['file' => $label, 'message' => __('That file name cannot be used.')];

                continue;
            }

            $key = ImageName::key($name);

            if (in_array($key, $takenKeys, true)) {
                $failed[] = ['file' => $label, 'message' => $this->duplicateMessage($name)];

                continue;
            }

            // Already in the folder: say which one, so the person can choose to replace it or skip.
            if ($existing = $this->existing($companyId, $key)) {
                $failed[] = [
                    'file' => $label,
                    'message' => $this->duplicateMessage($name),
                    'duplicate_of' => $existing->uuid,
                ];

                continue;
            }

            $takenKeys[] = $key;

            // Stored as a web-sized image; the row records what was actually kept.
            $stored = $this->store->put($file, $companyId);

            try {
                $saved[] = ProductImage::create([
                    'company_id' => $companyId,
                    'created_by' => $request->user()->id,
                    'name' => $name,
                    'name_key' => $key,
                    'path' => $stored['path'],
                    'mime' => $stored['mime'],
                    'size_bytes' => $stored['size'],
                ]);
            } catch (Throwable $e) {
                // No row means no way to ever delete it later: take the file back out now.
                $this->store->remove($stored['path']);

                throw $e;
            }
        }

        $payload = [
            'images' => ProductImageResource::collection(collect($saved)),
            'errors' => $failed,
        ];

        if ($saved === []) {
            return $this->error(
                $failed[0]['message'] ?? __('Nothing was uploaded.'),
                422,
                ['files' => $failed],
            );
        }

        return $this->success(
            $payload,
            trans_choice('{1} :count image uploaded.|[2,*] :count images uploaded.', count($saved), [
                'count' => count($saved),
            ]),
            201,
        );
    }

    /**
     * Puts a new picture in place of an existing one. The row, its name and every product that points at
     * it stay as they are; only the file changes.
     */
    public function replace(Request $request, ProductImage $productImage): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        if ($reason = $this->store->reject($file)) {
            return $this->error($reason, 422, ['file' => [$reason]]);
        }

        $oldPath = $productImage->path;
        $stored = $this->store->put($file, (int) $productImage->company_id);

        try {
            $productImage->update([
                'path' => $stored['path'],
                'mime' => $stored['mime'],
                'size_bytes' => $stored['size'],
            ]);
        } catch (Throwable $e) {
            $this->store->remove($stored['path']);

            throw $e;
        }

        if (! $this->store->remove($oldPath)) {
            Log::warning('Replaced product image left its old file behind: '.$oldPath);
        }

        return $this->success(new ProductImageResource($productImage->fresh()), __('File replaced.'));
    }

    /** Only the label moves. The file keeps the path it was written to. */
    public function update(Request $request, ProductImage $productImage): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = ImageName::clean($request->string('name')->toString());

        if ($name === null) {
            return $this->error(__('Enter a file name.'), 422, [
                'name' => [__('Enter a file name.')],
            ]);
        }

        $key = ImageName::key($name);

        if ($this->keyTaken((int) $productImage->company_id, $key, $productImage->id)) {
            $message = $this->duplicateMessage($name);

            return $this->error($message, 422, ['name' => [$message]]);
        }

        $productImage->update(['name' => $name, 'name_key' => $key]);

        return $this->success(new ProductImageResource($productImage->fresh()), __('File renamed.'));
    }

    public function destroy(ProductImage $productImage): JsonResponse
    {
        $path = $productImage->path;

        // The row and the file go together. If the file will not delete, the row stays
        // too, so nothing is left behind in storage without a record.
        try {
            DB::transaction(function () use ($productImage, $path) {
                $productImage->delete();

                if (! $this->store->remove($path)) {
                    throw new RuntimeException('Stored file could not be deleted: '.$path);
                }
            });
        } catch (RuntimeException $e) {
            Log::error($e->getMessage());

            return $this->error(__('The file could not be removed from storage. Nothing was deleted, please try again.'), 500);
        }

        return $this->success([], __('File deleted.'));
    }

    /** Streams the bytes for a member of the owning company only. */
    public function file(ProductImage $productImage): StreamedResponse
    {
        $disk = Storage::disk(ProductImageStore::DISK);

        abort_unless($disk->exists($productImage->path), 404);

        return $disk->response($productImage->path, $productImage->name, [
            'Content-Type' => $productImage->mime,
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    private function existing(int $companyId, string $key): ?ProductImage
    {
        return ProductImage::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name_key', $key)
            ->first();
    }

    private function keyTaken(int $companyId, string $key, ?int $ignoreId = null): bool
    {
        return ProductImage::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name_key', $key)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    private function duplicateMessage(string $name): string
    {
        return __('A file named :name is already in this folder.', ['name' => $name]);
    }
}
