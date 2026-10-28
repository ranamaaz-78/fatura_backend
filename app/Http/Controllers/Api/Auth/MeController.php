<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Services\Demo\DemoSwitch;
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
            // Every "area.action" this person may use; the owner has them all.
            'permissions' => $user->permissionList(),
            // The language this person sees: their own, else their company's, else the default.
            // In the shared demo the visitor keeps the language they are already using (the request's), instead of the
            // demo company's, so going in and out never switches the app's language under them.
            'locale' => $company?->is_demo ? app()->getLocale() : $user->preferredLocale(),
            // Whether the practice account is open: the dashboard card and the Ctrl+K entry hide when it is not.
            'demo_enabled' => app(DemoSwitch::class)->enabled(),
            'company' => $company ? new CompanyResource($company) : null,
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'support' => [
                'email' => config('fatura.support.email'),
                'whatsapp' => config('fatura.support.whatsapp'),
            ],
        ]);
    }
}
