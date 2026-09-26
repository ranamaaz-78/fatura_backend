<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\SubscriptionResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $subscription = $company?->activeSubscription ?? $company?->latestSubscription;

        return $this->success([
            'company' => $company ? new CompanyResource($company) : null,
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'support' => [
                'email' => config('fatura.support.email'),
                'whatsapp' => config('fatura.support.whatsapp'),
            ],
        ]);
    }
}
