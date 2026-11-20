<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Services\ContactImportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierImportController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, ContactImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*' => ['array'],
        ]);

        $result = $importer->importSuppliers((int) $request->user()->company_id, $data['rows']);

        if ($result['errors'] !== []) {
            return $this->error(__('Some rows still need fixing.'), 422, ['rows' => $result['errors']]);
        }

        return $this->success(
            ['created' => $result['created']],
            trans_choice('{1} :count supplier imported.|[2,*] :count suppliers imported.', $result['created'], ['count' => $result['created']]),
            201,
        );
    }
}
