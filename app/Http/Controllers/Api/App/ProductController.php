<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\TaxRate;
use App\Services\BarcodeAllocator;
use App\Support\Pricing;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $data = $this->priced($data);

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

        $product->fill($this->priced($data, $product));
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
            'selling_price' => [$required, 'integer', 'min:0'],
            'iva_percent' => [$required, 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /**
     * Selling price is what the user typed, and it does not include IVA.
     * Margin is only the gap between the two prices, kept for the catalog.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function priced(array $data, ?Product $product = null): array
    {
        $buying = array_key_exists('buying_price', $data)
            ? (int) $data['buying_price']
            : (int) ($product->buying_price ?? 0);
        $selling = array_key_exists('selling_price', $data)
            ? (int) $data['selling_price']
            : (int) ($product->selling_price ?? 0);
        $touchesPrice = $product === null
            || array_key_exists('buying_price', $data)
            || array_key_exists('selling_price', $data);

        if ($touchesPrice && $selling <= $buying) {
            throw ValidationException::withMessages([
                'selling_price' => __('Selling price must be higher than the buying price.'),
            ]);
        }

        if (array_key_exists('iva_percent', $data)) {
            $rate = round((float) $data['iva_percent'], 2);
            $unchanged = $product !== null && round((float) $product->iva_percent, 2) === $rate;

            if (! $unchanged && ! TaxRate::allows((int) ($product->company_id ?? request()->user()->company_id), $rate)) {
                throw ValidationException::withMessages([
                    'iva_percent' => __('Choose an IVA rate from your settings.'),
                ]);
            }
        }

        if ($touchesPrice) {
            $data['buying_price'] = $buying;
            $data['selling_price'] = $selling;
            $data['margin_percent'] = Pricing::marginFromPrices($buying, $selling);
        }

        return $data;
    }
}
