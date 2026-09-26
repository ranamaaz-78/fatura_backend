<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\ActivityType;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicationRequest;
use App\Models\Application;
use App\Models\ApplicationActivity;
use App\Models\Plan;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ApplicationController extends Controller
{
    use ApiResponse;

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Bots fill hidden fields. Answer like a success so they stop retrying.
        if (filled($data['website'] ?? null)) {
            return $this->accepted();
        }

        $email = strtolower(trim($data['email']));

        $duplicate = Application::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', ApplicationStatus::New)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($duplicate) {
            return $this->accepted();
        }

        $planId = $data['plan_id'] ?? null;

        if ($planId === null && filled($data['plan_slug'] ?? null)) {
            $planId = Plan::active()->where('slug', $data['plan_slug'])->value('id');
        }

        $application = Application::create([
            'company_name' => $data['company_name'],
            'contact_name' => $data['contact_name'],
            'email' => $email,
            'phone' => $data['phone'],
            'whatsapp' => $data['whatsapp'] ?? null,
            'city' => $data['city'] ?? null,
            'country' => $data['country'] ?? null,
            'business_type' => $data['business_type'] ?? null,
            'team_size' => $data['team_size'] ?? null,
            'message' => $data['message'] ?? null,
            'plan_id' => $planId,
            'status' => ApplicationStatus::New,
            'source' => 'website',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        ApplicationActivity::create([
            'application_id' => $application->id,
            'type' => ActivityType::Created,
            'body' => __('Application received from the website.'),
        ]);

        return $this->accepted();
    }

    private function accepted(): JsonResponse
    {
        return $this->success(
            ['received' => true],
            __('Thanks! We received your application and will contact you shortly.'),
            201,
        );
    }
}
