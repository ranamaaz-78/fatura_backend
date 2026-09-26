<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        $subscription = $company?->activeSubscription;

        return $this->success([
            'user' => new UserResource($user),
            'role' => $user->role->value,
            'company' => $company ? new CompanyResource($company) : null,
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'support' => [
                'email' => config('fatura.support.email'),
                'whatsapp' => config('fatura.support.whatsapp'),
            ],
        ]);
    }
}
