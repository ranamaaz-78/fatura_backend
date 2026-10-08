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
        $monthStart = now()->startOfMonth();

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
                'currency' => 'EUR',
                'this_month' => (float) Payment::withoutGlobalScopes()
                    ->where('status', PaymentStatus::Paid)
                    ->where('paid_at', '>=', now()->startOfMonth())
                    ->sum('amount'),
                'all_time' => (float) Payment::withoutGlobalScopes()
                    ->where('status', PaymentStatus::Paid)
                    ->sum('amount'),
                'last_month' => (float) Payment::withoutGlobalScopes()
                    ->where('status', PaymentStatus::Paid)
                    ->whereBetween('paid_at', [$monthStart->copy()->subMonth(), $monthStart])
                    ->sum('amount'),
                'trend' => $this->revenueTrend(),
            ],
            'application_trend' => $this->applicationTrend(),
            'conversion' => [
                'total' => Application::count(),
                'converted' => Application::whereNotNull('converted_company_id')->count(),
            ],
            'needs_attention' => $this->needsAttention($graceDays),
            'plan_mix' => $this->planMix(),
            'latest_payments' => $this->latestPayments(),
            'latest_applications' => ApplicationResource::collection(
                Application::with('plan')->latest('id')->limit(5)->get()
            ),
        ]);
    }

    /** Paid amount for each of the last six months, oldest first. */
    private function revenueTrend(): array
    {
        $from = now()->startOfMonth()->subMonths(5);

        $paid = Payment::withoutGlobalScopes()
            ->where('status', PaymentStatus::Paid)
            ->where('paid_at', '>=', $from)
            ->get(['amount', 'paid_at'])
            ->groupBy(fn ($payment) => $payment->paid_at->format('Y-m'));

        return collect(range(0, 5))->map(function ($i) use ($from, $paid) {
            $month = $from->copy()->addMonths($i);

            return [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M'),
                'amount' => (float) ($paid->get($month->format('Y-m'))?->sum('amount') ?? 0),
            ];
        })->all();
    }

    /** New applications for each of the last 14 days, oldest first. */
    private function applicationTrend(): array
    {
        $from = now()->subDays(13)->startOfDay();

        $received = Application::where('created_at', '>=', $from)
            ->get(['created_at'])
            ->groupBy(fn ($application) => $application->created_at->format('Y-m-d'));

        return collect(range(0, 13))->map(function ($i) use ($from, $received) {
            $day = $from->copy()->addDays($i);

            return [
                'date' => $day->format('Y-m-d'),
                'label' => $day->format('D'),
                'count' => $received->get($day->format('Y-m-d'))?->count() ?? 0,
            ];
        })->all();
    }

    /** Companies whose term ends within a week, and companies that have already lapsed. */
    private function needsAttention(int $graceDays): array
    {
        $row = fn (Subscription $subscription) => [
            'company_id' => $subscription->company_id,
            'company_name' => $subscription->company?->name,
            'plan_name' => $subscription->plan_name,
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'days_left' => $subscription->daysLeft(),
        ];

        $expiring = Subscription::withoutGlobalScopes()->with('company')
            ->where('status', SubscriptionStatus::Active)
            ->whereBetween('ends_at', [now(), now()->addDays(7)])
            ->orderBy('ends_at')
            ->limit(6)
            ->get()
            ->filter(fn ($subscription) => $subscription->company !== null)
            ->map($row)
            ->values();

        // Latest term per company that is no longer running.
        $expired = Subscription::withoutGlobalScopes()->with('company')
            ->whereIn('id', Subscription::withoutGlobalScopes()->selectRaw('MAX(id)')->groupBy('company_id'))
            ->where(fn ($query) => $query->where('status', SubscriptionStatus::Expired)
                ->orWhere(fn ($inner) => $inner->where('status', SubscriptionStatus::Active)
                    ->where('ends_at', '<=', now()->subDays($graceDays))))
            ->orderByDesc('ends_at')
            ->limit(6)
            ->get()
            ->filter(fn ($subscription) => $subscription->company !== null)
            ->map($row)
            ->values();

        return ['expiring' => $expiring, 'expired' => $expired];
    }

    /** How many running subscriptions sit on each plan. */
    private function planMix(): array
    {
        return Subscription::withoutGlobalScopes()
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '>', now())
            ->get(['plan_name', 'plan_price'])
            ->groupBy('plan_name')
            ->map(fn ($group, $name) => ['name' => $name, 'count' => $group->count(), 'monthly' => (float) $group->sum('plan_price')])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    private function latestPayments(): array
    {
        $payments = Payment::withoutGlobalScopes()->with('paymentMethod')
            ->where('status', PaymentStatus::Paid)
            ->latest('paid_at')
            ->limit(5)
            ->get();

        $companies = Company::whereIn('id', $payments->pluck('company_id'))->pluck('name', 'id');

        return $payments->map(fn ($payment) => [
            'id' => $payment->id,
            'company_id' => $payment->company_id,
            'company_name' => $companies->get($payment->company_id),
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'method' => $payment->paymentMethod?->name,
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
        ])->all();
    }
}
