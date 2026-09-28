<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\CompanyLogoStore;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyLogoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CompanyLogoStore $store) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');
        $reason = $this->store->reject($file);

        if ($reason !== null) {
            return $this->error($reason, 422);
        }

        $company = $request->user()->company;
        $path = $this->store->put($file, (int) $company->id, $company->logo_path);
        $company->update(['logo_path' => $path]);

        return $this->success([
            'logo_url' => '/app/company/logo',
        ], __('Logo saved.'));
    }

    public function destroy(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $this->store->remove($company->logo_path);
        $company->update(['logo_path' => null]);

        return $this->success([
            'logo_url' => null,
        ], __('Logo removed.'));
    }

    public function file(Request $request): StreamedResponse
    {
        $company = $request->user()->company;
        abort_unless((bool) $company?->logo_path, 404);

        $disk = Storage::disk(CompanyLogoStore::DISK);
        abort_unless($disk->exists($company->logo_path), 404);

        return $disk->response($company->logo_path, 'logo', [
            'Content-Type' => $disk->mimeType($company->logo_path) ?: 'image/png',
            'Cache-Control' => 'private, max-age=600',
        ]);
    }
}
