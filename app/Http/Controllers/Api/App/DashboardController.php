<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        $subscription = $company?->activeSubscription;

        // Module 01 has no invoicing data yet; a new company legitimately sees zeros.
        return $this->success([
            'company' => [
                'id' => $company?->id,
                'name' => $company?->name,
                'currency' => $company?->currency ?? 'USD',
            ],
            'kpis' => [
                'outstanding' => 0,
                'paid_this_month' => 0,
                'overdue' => 0,
                'invoices_this_month' => 0,
                'clients' => 0,
            ],
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'getting_started' => [
                ['key' => 'company_profile', 'label' => __('Complete your company profile'), 'done' => filled($company?->address)],
                ['key' => 'first_client', 'label' => __('Add your first client'), 'done' => false],
                ['key' => 'first_invoice', 'label' => __('Send your first invoice'), 'done' => false],
                ['key' => 'record_payment', 'label' => __('Record a payment'), 'done' => false],
            ],
        ]);
    }
}
