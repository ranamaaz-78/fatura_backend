<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DemoSession;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesDocument;
use App\Models\Supplier;
use App\Services\Demo\DemoCompany;
use App\Services\Demo\DemoResetter;
use App\Services\Demo\DemoSwitch;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** The platform admin's view of the shared demo account: how much it is used, and a button to clean it. */
class DemoController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DemoCompany $demo, private readonly DemoResetter $resetter, private readonly DemoSwitch $switch) {}

    public function show(): JsonResponse
    {
        return $this->success($this->payload());
    }

    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->switch->set((bool) $data['enabled']);

        return $this->success($this->payload(), $data['enabled'] ? __('The demo is on.') : __('The demo is off.'));
    }

    public function reset(): JsonResponse
    {
        $this->resetter->reset();

        return $this->success($this->payload(), __('The demo was reset.'));
    }

    private function payload(): array
    {
        $company = $this->demo->find();
        $count = fn (string $model) => $company === null ? 0 : $model::withoutGlobalScopes()->where('company_id', $company->id)->count();

        return [
            'enabled' => $this->switch->enabled(),
            'exists' => $company !== null,
            'last_reset' => Cache::get(DemoResetter::LAST_RESET),
            'entries_today' => DemoSession::query()->where('entered_at', '>=', now()->startOfDay())->count(),
            'entries_total' => DemoSession::query()->count(),
            'people_total' => DemoSession::query()->distinct()->count('user_id'),
            'data' => [
                'products' => $count(Product::class),
                'customers' => $count(Customer::class),
                'suppliers' => $count(Supplier::class),
                'documents' => $count(SalesDocument::class),
                'purchases' => $count(Purchase::class),
            ],
        ];
    }
}
