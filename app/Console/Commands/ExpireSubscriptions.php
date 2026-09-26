<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;

class ExpireSubscriptions extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Flag active subscriptions whose term (plus grace) has passed as expired';

    public function handle(): int
    {
        $graceDays = (int) config('fatura.subscriptions.grace_days');
        $cutoff = now()->subDays($graceDays);

        $count = Subscription::withoutGlobalScopes()
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '<=', $cutoff)
            ->update(['status' => SubscriptionStatus::Expired, 'updated_at' => now()]);

        $this->info("Expired {$count} subscription(s).");

        return self::SUCCESS;
    }
}
