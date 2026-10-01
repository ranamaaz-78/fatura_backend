<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\DocumentNumber;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumber $numbers) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $customers = Customer::query()
            ->withCount('documents')
            ->when($filters['phone'] ?? null, function (Builder $query, string $phone) {
                $needle = preg_replace('/[\s-]+/', '', $phone) ?? '';
                $query->whereRaw("REPLACE(REPLACE(phone, ' ', ''), '-', '') like ?", ['%'.$needle.'%']);
            })
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

        return $this->success(CustomerResource::collection($customers));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $customer = DB::transaction(fn () => Customer::create([
            ...$data,
            'company_id' => $request->user()->company_id,
            'code' => $this->numbers->take((int) $request->user()->company_id, 'client'),
        ]));

        return $this->success(new CustomerResource($customer), __('Client created.'), 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $customer->update($this->validated($request));

        return $this->success(new CustomerResource($customer->fresh()), __('Client updated.'));
    }

    public function updateActive(Request $request, Customer $customer): JsonResponse
    {
        $active = $request->validate([
            'is_active' => ['required', 'boolean'],
        ])['is_active'];

        $customer->update(['is_active' => $active]);
        $customer->loadCount('documents');

        return $this->success(
            new CustomerResource($customer),
            $active ? __('Client is active.') : __('Client is inactive.'),
        );
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();

        return $this->success([], __('Client deleted.'));
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
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        foreach (['company_name', 'phone', 'nif', 'nie', 'address'] as $field) {
            $data[$field] = ($data[$field] ?? '') !== '' ? $data[$field] : null;
        }

        return $data;
    }
}
