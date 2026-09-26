<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Company;
use App\Models\Subscription;
use App\Services\AccountProvisionNotifier;
use App\Services\SubscriptionService;
use App\Traits\ApiResponse;
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
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $companies = Company::query()
            ->with(['owner', 'activeSubscription'])
            ->withCount('users')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $like = '%'.$search.'%';
                $query->where(fn ($inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return $this->success([
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

        $whatsappUrl = $notifier->sendAccountReady($owner, $company, $subscription);

        return $this->success(
            ['whatsapp_url' => $whatsappUrl],
            __('A fresh set-password link has been sent.'),
        );
    }
}
