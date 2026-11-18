<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Services\SupplierLedger;
use Illuminate\Console\Command;

/**
 * Writes down in Supplier Payments the purchases that were made before the two were joined (or while Purchases was
 * switched off). Safe to run again: a purchase that already has its entry is left alone.
 */
class SyncPurchasesToLedger extends Command
{
    protected $signature = 'ledger:sync-purchases';

    protected $description = 'Write the purchases that have no Supplier Payments entry yet into Supplier Payments';

    public function handle(SupplierLedger $ledger): int
    {
        $made = 0;

        Purchase::withoutGlobalScopes()
            ->whereNull('voided_at')
            ->whereNotNull('supplier_id')
            ->whereDoesntHave('ledgerEntry')
            ->with('lines')
            ->orderBy('id')
            ->each(function (Purchase $purchase) use ($ledger, &$made) {
                if ($ledger->writeDownPurchase($purchase) !== null) {
                    $made++;
                }
            });

        $this->info("Entries written: {$made}");

        return self::SUCCESS;
    }
}
