<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\BarcodeAllocator;
use App\Support\Pricing;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer'],
            'stock' => ['nullable', Rule::in(['all', 'low', 'out', 'no_barcode'])],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $products = Product::query()
            ->with('category')
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $like = '%'.$search.'%';
                $query->where(function (Builder $inner) use ($like) {
                    $inner->where('article', 'like', $like)
                        ->orWhere('sr_number', 'like', $like)
                        ->orWhere('barcode', 'like', $like)
                        ->orWhere('brand', 'like', $like);
                });
            })
            ->tap(fn (Builder $query) => $this->applyStockFilter($query, $filters['stock'] ?? 'all'))
            ->orderBy('article')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return $this->success([
            'items' => ProductResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'counts' => [
                'all' => Product::count(),
                'low' => Product::lowStock()->count(),
                'out' => Product::outOfStock()->count(),
                'no_barcode' => Product::where('barcode_generated', true)->count(),
            ],
            // Stock value is held at cost, in cents, like every other amount.
            'stock_value' => (int) Product::query()->sum(DB::raw('quantity * buying_price')),
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        return $this->success(new ProductResource($product->load('category')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $companyId = $request->user()->company_id;

        $data['quantity'] ??= 0;
        $data['minimum_stock'] ??= 0;
        $data['barcode_generated'] = ($data['barcode'] ?? null) === null;
        $data['barcode'] ??= BarcodeAllocator::forCompany($companyId);
        $data['selling_price'] = Pricing::sellingPrice(
            $data['buying_price'],
            (float) $data['margin_percent'],
            (float) $data['iva_percent'],
        );

        $product = Product::create($data);

        return $this->success(new ProductResource($product->load('category')), __('Product created.'), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $this->validated($request, $product);

        if (array_key_exists('barcode', $data)) {
            if ($data['barcode'] === null) {
                $data['barcode'] = BarcodeAllocator::forCompany($product->company_id);
                $data['barcode_generated'] = true;
            } else {
                $data['barcode_generated'] = false;
            }
        }

        $product->fill($data);
        $product->selling_price = $product->expectedSellingPrice();
        $product->save();

        return $this->success(new ProductResource($product->fresh()->load('category')), __('Product updated.'));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return $this->success([], __('Product deleted.'));
    }

    public function generateBarcode(Request $request): JsonResponse
    {
        return $this->success(['barcode' => BarcodeAllocator::forCompany($request->user()->company_id)]);
    }

    private function applyStockFilter(Builder $query, string $stock): void
    {
        match ($stock) {
            'low' => $query->lowStock(),
            'out' => $query->outOfStock(),
            'no_barcode' => $query->where('barcode_generated', true),
            default => $query,
        };
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $required = $product === null ? 'required' : 'sometimes';
        $companyId = $request->user()->company_id;

        return $request->validate([
            'article' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'brand' => ['nullable', 'string', 'max:120'],
            'image_code' => ['nullable', 'string', 'max:120'],
            'sr_number' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'sr_number')->where('company_id', $companyId)->ignore($product?->id),
            ],
            'barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('products', 'barcode')->where('company_id', $companyId)->ignore($product?->id),
            ],
            'category_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('company_id', $companyId),
            ],
            'quantity' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'minimum_stock' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'buying_price' => [$required, 'integer', 'min:0'],
            'margin_percent' => [$required, 'numeric', 'min:0', 'max:100000'],
            'iva_percent' => [$required, 'numeric', 'min:0', 'max:100'],
        ]);
    }
}
