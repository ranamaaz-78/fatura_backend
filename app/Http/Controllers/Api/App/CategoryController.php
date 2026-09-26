<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $categories = Category::withCount('products')->orderBy('name')->get();

        return $this->success(CategoryResource::collection($categories));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $category = Category::create($data);

        return $this->success(new CategoryResource($category), __('Category created.'), 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $category->update($this->validated($request, $category));

        return $this->success(new CategoryResource($category->fresh()), __('Category updated.'));
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return $this->error(__('Move or delete the products in this category first.'), 422);
        }

        $category->delete();

        return $this->success([], __('Category deleted.'));
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('categories', 'name')
                    ->where('company_id', $request->user()->company_id)
                    ->ignore($category?->id),
            ],
        ]);
    }
}
