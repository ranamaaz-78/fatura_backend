<?php

namespace App\Http\Controllers\Api\Admin;

use App\Contracts\WhatsAppSender;
use App\Enums\ActivityType;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertApplicationRequest;
use App\Http\Resources\ApplicationActivityResource;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\CompanyResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Models\Application;
use App\Models\ApplicationActivity;
use App\Services\ConvertApplicationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ApplicationStatus::values())],
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $applications = Application::query()
            ->with('plan')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($inner) use ($search) {
                    $like = '%'.$search.'%';
                    $inner->where('company_name', 'like', $like)
                        ->orWhere('contact_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                });
            })
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return $this->success([
            'items' => ApplicationResource::collection($applications->items()),
            'meta' => [
                'current_page' => $applications->currentPage(),
                'last_page' => $applications->lastPage(),
                'per_page' => $applications->perPage(),
                'total' => $applications->total(),
            ],
            'counts' => [
                'all' => Application::count(),
                'new' => Application::where('status', ApplicationStatus::New)->count(),
                'contacted' => Application::where('status', ApplicationStatus::Contacted)->count(),
                'approved' => Application::where('status', ApplicationStatus::Approved)->count(),
                'rejected' => Application::where('status', ApplicationStatus::Rejected)->count(),
            ],
        ]);
    }

    public function show(Application $application): JsonResponse
    {
        $application->load(['plan', 'convertedCompany', 'activities.user']);

        return $this->success(new ApplicationResource($application));
    }

    public function updateStatus(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ApplicationStatus::values())],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($application->isConverted()) {
            return $this->error(__('A converted application cannot change status.'), 422);
        }

        $from = $application->status->value;
        $application->update(['status' => $data['status']]);

        ApplicationActivity::create([
            'application_id' => $application->id,
            'user_id' => $request->user()->id,
            'type' => ActivityType::StatusChanged,
            'body' => $data['note'] ?? null,
            'meta' => ['from' => $from, 'to' => $data['status']],
        ]);

        return $this->success(
            new ApplicationResource($application->fresh('plan')),
            __('Status updated.'),
        );
    }

    public function update(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $application->update($data);

        return $this->success(new ApplicationResource($application->fresh('plan')), __('Saved.'));
    }

    public function destroy(Application $application): JsonResponse
    {
        if ($application->isConverted()) {
            return $this->error(__('A converted application cannot be deleted.'), 422);
        }

        $application->delete();

        return $this->success([], __('Application deleted.'));
    }

    public function storeActivity(Request $request, Application $application): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([
                ActivityType::Note->value,
                ActivityType::Call->value,
                ActivityType::WhatsApp->value,
                ActivityType::Email->value,
            ])],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $activity = ApplicationActivity::create([
            'application_id' => $application->id,
            'user_id' => $request->user()->id,
            'type' => $data['type'],
            'body' => $data['body'] ?? null,
        ]);

        return $this->success(
            new ApplicationActivityResource($activity->load('user')),
            __('Logged.'),
            201,
        );
    }

    public function whatsappLink(Request $request, Application $application, WhatsAppSender $sender): JsonResponse
    {
        $number = $application->whatsappNumber();

        if (blank($number)) {
            return $this->error(__('This application has no WhatsApp number.'), 422);
        }

        $message = $request->string('message')->toString() ?: __(
            'Hi :name, this is :app about your application for :company. Is now a good time to talk?',
            [
                'name' => $application->contact_name,
                'app' => config('app.name'),
                'company' => $application->company_name,
            ],
        );

        $result = $sender->send($number, $message);

        if ($result->url === null) {
            return $this->error($result->error ?? __('Could not build a WhatsApp link.'), 422);
        }

        return $this->success(['whatsapp_url' => $result->url, 'message' => $message]);
    }

    public function convert(
        ConvertApplicationRequest $request,
        Application $application,
        ConvertApplicationService $service,
    ): JsonResponse {
        if ($application->isConverted()) {
            return $this->error(__('This application has already been converted.'), 422);
        }

        $result = $service->handle($application, $request->validated(), $request->user());

        return $this->success([
            'company' => new CompanyResource($result['company']),
            'owner' => new UserResource($result['owner']),
            'subscription' => new SubscriptionResource($result['subscription']),
            'whatsapp_url' => $result['whatsapp_url'],
            'email_sent' => $result['email_sent'],
            'email_error' => $result['email_error'],
            'application' => new ApplicationResource($application->fresh('plan')),
        ], $result['email_sent']
            ? __('Account created. The owner has been emailed a set-password link.')
            : __('Account created, but the set-password email could not be sent. Use Resend access link once the mail problem is fixed.'), 201);
    }
}
