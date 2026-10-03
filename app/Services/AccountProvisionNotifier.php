<?php

namespace App\Services;

use App\Contracts\WhatsAppSender;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Mail\AccountReadyMail;
use App\Models\Application;
use App\Models\Company;
use App\Support\Locales;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AccountProvisionNotifier
{
    public function __construct(
        private readonly InviteService $invites,
        private readonly WhatsAppSender $whatsapp,
        private readonly NotificationLogger $logger,
    ) {}

    /**
     * Mint a fresh invite link and deliver it by email + WhatsApp.
     *
     * The email goes out straight away instead of waiting on a queue worker, so the admin
     * learns on the spot whether it left. A failure never undoes the account.
     */
    public function sendAccountReady(User $owner, Company $company, Subscription $subscription, ?Application $application = null): AccountReadyDelivery
    {
        $url = $this->invites->urlFor($owner);
        $locale = Locales::normalize($company->locale) ?? Locales::DEFAULT;
        $context = [
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'application_id' => $application?->id,
        ];

        $emailError = null;

        try {
            Mail::to($owner->email)->locale($locale)->sendNow(new AccountReadyMail($owner, $company, $subscription, $url));
        } catch (Throwable $e) {
            $emailError = $e->getMessage();
            Log::error('Account-ready email failed: '.$emailError);
        }

        $this->logger->log(
            NotificationChannel::Email,
            'account_ready',
            $owner->email,
            $emailError === null ? NotificationStatus::Sent : NotificationStatus::Failed,
            $context + ['error' => $emailError],
        );

        $number = $owner->whatsapp ?: $owner->phone;

        if (blank($number)) {
            return new AccountReadyDelivery($emailError === null, $emailError);
        }

        $result = $this->whatsapp->send($number, $this->message($owner, $company, $url, $locale));

        $this->logger->log(
            NotificationChannel::WhatsApp,
            'account_ready',
            $number,
            $result->status,
            [
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'application_id' => $application?->id,
                'payload' => ['url' => $result->url],
                'error' => $result->error,
            ],
        );

        return new AccountReadyDelivery($emailError === null, $emailError, $result->url);
    }

    private function message(User $owner, Company $company, string $url, string $locale): string
    {
        return __(':greeting Your :app account for :company is ready. Set your password here: :url (the link expires in 48 hours).', [
            'greeting' => __('Hi :name,', ['name' => $owner->name], $locale),
            'app' => config('app.name'),
            'company' => $company->name,
            'url' => $url,
        ], $locale);
    }
}
