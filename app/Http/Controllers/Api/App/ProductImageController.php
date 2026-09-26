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
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

            if (in_array($key, $takenKeys, true) || $this->keyTaken($companyId, $key)) {
                $failed[] = ['file' => $label, 'message' => $this->duplicateMessage($name)];

                continue;
            }

            $takenKeys[] = $key;

            $saved[] = ProductImage::create([
                'company_id' => $companyId,
                'created_by' => $request->user()->id,
                'name' => $name,
                'name_key' => $key,
                'path' => $this->store->put($file, $companyId),
                'mime' => $this->store->mime($file),
                'size_bytes' => (int) $file->getSize(),
            ]);
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

        DB::transaction(function () use ($productImage, $path) {
            $productImage->delete();
            $this->store->remove($path);
        });

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
