<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\ProductImportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyProductImportController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, Company $company, ProductImportService $importer): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*' => ['array'],
        ]);

        $result = $importer->import($company->id, $data['rows']);

        if ($result['errors'] !== []) {
            return $this->error(__('Some rows still need fixing.'), 422, ['rows' => $result['errors']]);
        }

        return $this->success(
            ['created' => $result['created']],
            trans_choice('{1} :count product imported.|[2,*] :count products imported.', $result['created'], ['count' => $result['created']]),
            201,
        );
    }
}
