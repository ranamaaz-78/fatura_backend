<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesDocument;
use App\Models\Supplier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps one visitor from filling the shared demo: past a limit, more can be added only after tonight's reset.
 * It only ever applies to the demo company; a real company is never limited.
 *
 * Used as `demo.cap:products,150`.
 */
class DemoCap
{
    private const MODELS = [
        'products' => Product::class,
        'documents' => SalesDocument::class,
        'customers' => Customer::class,
        'suppliers' => Supplier::class,
        'purchases' => Purchase::class,
    ];

    public function handle(Request $request, Closure $next, string $what, string $max): Response
    {
        if ($request->user()?->company?->is_demo && isset(self::MODELS[$what])) {
            $model = self::MODELS[$what];

            if ($model::query()->count() >= (int) $max) {
                return response()->json([
                    'success' => false,
                    'message' => __('The demo is full. It is cleared every night, so try again tomorrow.'),
                    'data' => [],
                    'code' => 'DEMO_FULL',
                ], 422);
            }
        }

        return $next($request);
    }
}
