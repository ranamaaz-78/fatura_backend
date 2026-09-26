<?php

namespace App\Console\Commands;

use App\Contracts\WhatsAppSender;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\SubscriptionExpiringMail;
use App\Models\NotificationLog;
use App\Models\Subscription;
use App\Services\NotificationLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class RemindExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:remind';

    protected $description = 'Warn company owners 7, 3 and 1 days before their subscription ends';

    public function handle(WhatsAppSender $whatsapp, NotificationLogger $logger): int
    {
        $sent = 0;

        foreach ((array) config('fatura.subscriptions.reminder_days') as $days) {
            $window = [now()->addDays($days)->startOfDay(), now()->addDays($days)->endOfDay()];

            $subscriptions = Subscription::withoutGlobalScopes()
                ->with('company.owner')
                ->where('status', SubscriptionStatus::Active)
                ->whereBetween('ends_at', $window)
                ->get();

            foreach ($subscriptions as $subscription) {
                $company = $subscription->company;
                $owner = $company?->owner;

                if ($owner === null) {
                    continue;
                }

                $type = "subscription_expiring_{$days}d";

                $alreadySent = NotificationLog::where('type', $type)
                    ->where('user_id', $owner->id)
                    ->where('created_at', '>=', now()->subDays(2))
                    ->exists();

                if ($alreadySent) {
                    continue;
                }

                Mail::to($owner->email)->queue(new SubscriptionExpiringMail($owner, $company, $subscription, (int) $days));

                $logger->log(NotificationChannel::Email, $type, $owner->email, NotificationStatus::Queued, [
                    'company_id' => $company->id,
                    'user_id' => $owner->id,
                ]);

                $number = $owner->whatsapp ?: $owner->phone;

                if (filled($number)) {
                    $result = $whatsapp->send($number, __(
                        'Your :app subscription for :company ends in :days day(s), on :date. Reply here to renew.',
                        [
                            'app' => config('app.name'),
                            'company' => $company->name,
                            'days' => $days,
                            'date' => $subscription->ends_at->toFormattedDateString(),
                        ],
                    ));

                    $logger->log(NotificationChannel::WhatsApp, $type, $number, $result->status, [
                        'company_id' => $company->id,
                        'user_id' => $owner->id,
                        'payload' => ['url' => $result->url],
                        'error' => $result->error,
                    ]);
                }

                $sent++;
            }
        }

        $this->info("Queued {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
