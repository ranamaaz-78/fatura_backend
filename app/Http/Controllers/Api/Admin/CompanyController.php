<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CompanyStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\AccountProvisionNotifier;
use App\Services\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(CompanyStatus::values())],
            'subscription' => ['nullable', Rule::in(['active', 'expiring', 'expired', 'none'])],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $companies = Company::query()
            ->with(['owner', 'activeSubscription', 'latestSubscription'])
            ->withCount('users')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['subscription'] ?? null, fn ($query, $state) => $this->inSubscriptionState($query, $state))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $like = '%'.$search.'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return $this->success([
            'counts' => $this->counts(),
            'items' => CompanyResource::collection($companies->items()),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
            ],
        ]);
    }

    public function show(Company $company): JsonResponse
    {
        $company->load([
            'owner',
            'activeSubscription',
            'latestSubscription',
            'subscriptions' => fn ($query) => $query->withoutGlobalScopes()->with('payments.paymentMethod')->latest('id'),
        ])->loadCount('users');

        return $this->success(new CompanyResource($company));
    }

    public function updateStatus(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(CompanyStatus::values())],
        ]);

        $company->update($data);

        return $this->success(new CompanyResource($company->fresh(['owner', 'activeSubscription'])), __('Company updated.'));
    }

    public function subscriptions(Request $request, Company $company, SubscriptionService $service): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')],
            'periods' => ['nullable', 'integer', 'min:1', 'max:36'],
            'starts_at' => ['nullable', 'date'],
            'subscription_notes' => ['nullable', 'string', 'max:2000'],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
            'payment_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $subscription = $service->start($company, $data, $request->user());

        return $this->success(
            new SubscriptionResource($subscription->load('payments.paymentMethod')),
            __('Subscription started.'),
            201,
        );
    }

    public function cancelSubscription(Subscription $subscription, SubscriptionService $service): JsonResponse
    {
        return $this->success(
            new SubscriptionResource($service->cancel($subscription)),
            __('Subscription cancelled.'),
        );
    }

    public function resendAccess(Company $company, AccountProvisionNotifier $notifier): JsonResponse
    {
        $owner = $company->owner;
        $subscription = $company->activeSubscription ?? $company->latestSubscription;

        if ($owner === null || $subscription === null) {
            return $this->error(__('This company has no owner or subscription to send access for.'), 422);
        }

        $delivery = $notifier->sendAccountReady($owner, $company, $subscription);

        if (! $delivery->emailSent) {
            // The link was still minted, so the admin can pass it on over WhatsApp.
            return response()->json([
                'success' => false,
                'message' => __('The email could not be sent to :email. Check the mail settings, or send the link on WhatsApp.', ['email' => $owner->email]),
                'data' => ['whatsapp_url' => $delivery->whatsappUrl, 'email_error' => $delivery->emailError],
                'code' => 'MAIL_FAILED',
            ], 503);
        }

        return $this->success(
            ['whatsapp_url' => $delivery->whatsappUrl, 'email_sent' => true],
            __('A fresh set-password link has been emailed to :email.', ['email' => $owner->email]),
        );
    }

    /** Days before the end of a term at which a company counts as "expiring soon". */
    private const EXPIRING_DAYS = 7;

    /** A term that is still running, grace days included. */
    private function live(Builder $query): void
    {
        $graceDays = (int) config('fatura.subscriptions.grace_days');

        $query->where('status', SubscriptionStatus::Active->value)
            ->where('ends_at', '>', now()->subDays($graceDays));
    }

    private function inSubscriptionState(Builder $query, string $state): Builder
    {
        return match ($state) {
            'active' => $query->whereHas('subscriptions', fn ($sub) => $this->live($sub)),
            'expiring' => $query->whereHas('subscriptions', function ($sub) {
                $this->live($sub);
                $sub->where('ends_at', '<=', now()->addDays(self::EXPIRING_DAYS));
            }),
            'expired' => $query
                ->whereHas('subscriptions')
                ->whereDoesntHave('subscriptions', fn ($sub) => $this->live($sub)),
            default => $query->whereDoesntHave('subscriptions'),
        };
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'all' => Company::count(),
            'active' => $this->inSubscriptionState(Company::query(), 'active')->count(),
            'expiring' => $this->inSubscriptionState(Company::query(), 'expiring')->count(),
            'expired' => $this->inSubscriptionState(Company::query(), 'expired')->count(),
            'none' => $this->inSubscriptionState(Company::query(), 'none')->count(),
            'suspended' => Company::where('status', CompanyStatus::Suspended->value)->count(),
        ];
    }
}
