<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Locales;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The platform admin has no company, so the language is their own. */
class LocaleController extends Controller
{
    use ApiResponse;

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(Locales::SUPPORTED)],
        ]);

        $request->user()->update(['locale' => $data['locale']]);

        return $this->success(new UserResource($request->user()->fresh()), __('Language saved.'));
    }
}
