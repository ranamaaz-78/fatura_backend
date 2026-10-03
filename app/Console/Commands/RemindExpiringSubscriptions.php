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
use App\Support\Fmt;
use App\Support\Locales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

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

                $locale = Locales::normalize($company->locale) ?? Locales::DEFAULT;

                // Sent from the scheduler itself, so this works without a queue worker running.
                $emailError = null;

                try {
                    Mail::to($owner->email)->locale($locale)->sendNow(new SubscriptionExpiringMail($owner, $company, $subscription, (int) $days));
                } catch (Throwable $e) {
                    $emailError = $e->getMessage();
                    $this->warn("Could not email {$owner->email}: {$emailError}");
                }

                $logger->log(
                    NotificationChannel::Email,
                    $type,
                    $owner->email,
                    $emailError === null ? NotificationStatus::Sent : NotificationStatus::Failed,
                    ['company_id' => $company->id, 'user_id' => $owner->id, 'error' => $emailError],
                );

                $number = $owner->whatsapp ?: $owner->phone;

                if (filled($number)) {
                    $result = $whatsapp->send($number, trans_choice(
                        'Your :app subscription for :company ends in :count day, on :date. Reply here to renew.|Your :app subscription for :company ends in :count days, on :date. Reply here to renew.',
                        (int) $days,
                        [
                            'app' => config('app.name'),
                            'company' => $company->name,
                            'date' => Fmt::date($subscription->ends_at, $locale),
                        ],
                        $locale,
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

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
