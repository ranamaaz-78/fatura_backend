<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Services\DocumentNumber;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumber $numbers) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $suppliers = Supplier::query()
            ->withCount('products')
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.$search.'%';
                $query->where(function (Builder $inner) use ($like) {
                    $inner->where('name', 'like', $like)
                        ->orWhere('company_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('nif', 'like', $like)
                        ->orWhere('nie', 'like', $like)
                        ->orWhere('code', 'like', $like);
                });
            })
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('name')
            ->limit($filters['limit'] ?? 500)
            ->get();

        return $this->success(SupplierResource::collection($suppliers));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $supplier = DB::transaction(fn () => Supplier::create([
            ...$data,
            'company_id' => $request->user()->company_id,
            'code' => $this->numbers->take((int) $request->user()->company_id, 'supplier'),
        ]));

        return $this->success(new SupplierResource($supplier), __('Supplier created.'), 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($this->validated($request));

        return $this->success(new SupplierResource($supplier->fresh()), __('Supplier updated.'));
    }

    public function updateActive(Request $request, Supplier $supplier): JsonResponse
    {
        $active = $request->validate([
            'is_active' => ['required', 'boolean'],
        ])['is_active'];

        $supplier->update(['is_active' => $active]);
        $supplier->loadCount('products');

        return $this->success(
            new SupplierResource($supplier),
            $active ? __('Supplier is active.') : __('Supplier is inactive.'),
        );
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $supplier->delete();

        return $this->success([], __('Supplier deleted.'));
    }

    /** @return array<string, string|null> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'company_name' => ['nullable', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'nif' => ['nullable', 'string', 'max:32'],
            'nie' => ['nullable', 'string', 'max:32'],
        ]);

        foreach (['company_name', 'phone', 'nif', 'nie'] as $field) {
            $data[$field] = ($data[$field] ?? '') !== '' ? $data[$field] : null;
        }

        return $data;
    }
}
