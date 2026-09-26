<?php

namespace App\Services;

use App\Contracts\WhatsAppSender;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Mail\AccountReadyMail;
use App\Models\Application;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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
     * @return string|null wa.me URL when the link driver is active
     */
    public function sendAccountReady(User $owner, Company $company, Subscription $subscription, ?Application $application = null): ?string
    {
        $url = $this->invites->urlFor($owner);

        Mail::to($owner->email)->queue(new AccountReadyMail($owner, $company, $subscription, $url));

        $this->logger->log(
            NotificationChannel::Email,
            'account_ready',
            $owner->email,
            NotificationStatus::Queued,
            [
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'application_id' => $application?->id,
            ],
        );

        $number = $owner->whatsapp ?: $owner->phone;

        if (blank($number)) {
            return null;
        }

        $result = $this->whatsapp->send($number, $this->message($owner, $company, $url));

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

        return $result->url;
    }

    private function message(User $owner, Company $company, string $url): string
    {
        return __(':greeting Your :app account for :company is ready. Set your password here: :url (the link expires in 48 hours).', [
            'greeting' => __('Hi :name,', ['name' => $owner->name]),
            'app' => config('app.name'),
            'company' => $company->name,
            'url' => $url,
        ]);
    }
}
