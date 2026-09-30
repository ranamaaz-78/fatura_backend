<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\ActivityType;
use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\RequestApplicationOtpRequest;
use App\Http\Requests\StoreApplicationRequest;
use App\Mail\ApplicationOtpMail;
use App\Models\Application;
use App\Models\ApplicationActivity;
use App\Models\Plan;
use App\Models\User;
use App\Services\ApplicationOtp;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ApplicationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ApplicationOtp $otp) {}

    /** Step 1: check the details, then email a code. Nothing is saved yet. */
    public function requestOtp(RequestApplicationOtpRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Bots fill hidden fields. Answer like a success so they stop retrying.
        if (filled($data['website'] ?? null)) {
            return $this->codeSent(ApplicationOtp::RESEND_SECONDS);
        }

        $email = strtolower(trim($data['email']));

        if ($blocked = $this->blockedEmail($email)) {
            return $blocked;
        }

        // A code went out a moment ago and is still valid: just take them to enter it.
        if (($wait = $this->otp->waitSeconds($email)) > 0) {
            return $this->codeSent($wait);
        }

        $code = $this->otp->issue($email);

        try {
            Mail::to($email)->send(new ApplicationOtpMail($data['contact_name'], $code));
        } catch (Throwable $e) {
            $this->otp->discard($email);
            Log::error('Application OTP email failed: '.$e->getMessage());

            return $this->error(__('We could not send the email right now. Please try again in a moment.'), 503);
        }

        return $this->codeSent(ApplicationOtp::RESEND_SECONDS);
    }

    /** Step 2: the code proves the email; only then is the application saved. */
    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Bots fill hidden fields. Answer like a success so they stop retrying.
        if (filled($data['website'] ?? null)) {
            return $this->accepted();
        }

        $email = strtolower(trim($data['email']));

        if ($blocked = $this->blockedEmail($email)) {
            return $blocked;
        }

        $outcome = $this->otp->verify($email, $data['otp']);

        if ($outcome !== ApplicationOtp::OK) {
            $message = match ($outcome) {
                ApplicationOtp::EXPIRED => __('That code has expired. Request a new one.'),
                ApplicationOtp::LOCKED => __('Too many wrong attempts. Request a new code.'),
                default => __('That code is not correct.'),
            };

            return $this->error($message, 422, ['otp' => [$message]]);
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
            'body' => __('Application received from the website. Email verified by code.'),
        ]);

        return $this->accepted();
    }

    /** An email that has an account, or a live application, cannot apply. */
    private function blockedEmail(string $email): ?JsonResponse
    {
        // An email that already has an account should log in, not apply again.
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $message = __('An account with this email already exists. Please log in instead.');

            return $this->error($message, 422, ['email' => [$message]]);
        }

        // One live application per email. Only a rejected one frees the address
        // to apply again; new, contacted and approved ones all still count.
        $open = Application::query()
            ->where('email', $email)
            ->where('status', '!=', ApplicationStatus::Rejected->value)
            ->exists();

        if ($open) {
            $message = __('An application with this email is already in progress. We will contact you soon.');

            return $this->error($message, 422, ['email' => [$message]]);
        }

        return null;
    }

    private function codeSent(int $resendIn): JsonResponse
    {
        return $this->success(
            ['sent' => true, 'expires_in' => ApplicationOtp::TTL_SECONDS, 'resend_in' => $resendIn],
            __('We emailed you a verification code.'),
        );
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
