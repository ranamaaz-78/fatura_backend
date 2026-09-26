<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\CompanyStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Subscription;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __invoke(): JsonResponse
    {
        $graceDays = (int) config('fatura.subscriptions.grace_days');

        return $this->success([
            'applications' => [
                'new' => Application::where('status', ApplicationStatus::New)->count(),
                'contacted' => Application::where('status', ApplicationStatus::Contacted)->count(),
                'approved' => Application::where('status', ApplicationStatus::Approved)->count(),
                'rejected' => Application::where('status', ApplicationStatus::Rejected)->count(),
                'this_week' => Application::where('created_at', '>=', now()->subWeek())->count(),
            ],
            'companies' => [
                'total' => Company::count(),
                'active' => Company::where('status', CompanyStatus::Active)->count(),
                'suspended' => Company::where('status', CompanyStatus::Suspended)->count(),
            ],
            'subscriptions' => [
                'active' => Subscription::withoutGlobalScopes()
                    ->where('status', SubscriptionStatus::Active)
                    ->where('ends_at', '>', now())
                    ->count(),
                'expiring_soon' => Subscription::withoutGlobalScopes()
                    ->where('status', SubscriptionStatus::Active)
                    ->whereBetween('ends_at', [now(), now()->addDays(7)])
                    ->count(),
                'expired' => Subscription::withoutGlobalScopes()
                    ->where(function ($query) use ($graceDays) {
                        $query->where('status', SubscriptionStatus::Expired)
                            ->orWhere('ends_at', '<=', now()->subDays($graceDays));
                    })
                    ->count(),
            ],
            'revenue' => [
                'currency' => 'USD',
                'this_month' => (float) Payment::withoutGlobalScopes()
                    ->where('status', PaymentStatus::Paid)
                    ->where('paid_at', '>=', now()->startOfMonth())
                    ->sum('amount'),
                'all_time' => (float) Payment::withoutGlobalScopes()
                    ->where('status', PaymentStatus::Paid)
                    ->sum('amount'),
            ],
            'latest_applications' => ApplicationResource::collection(
                Application::with('plan')->latest('id')->limit(5)->get()
            ),
        ]);
    }
}
