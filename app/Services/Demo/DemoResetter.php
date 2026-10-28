<?php

namespace App\Services\Demo;

use App\Models\Company;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Puts the shared demo back to its starting state: everything the practicing customers made is deleted and the sample
 * data is written again. The company, its owner and the live tokens stay, so nobody in the middle of a session is
 * thrown out; they just see the clean data on their next screen.
 */
class DemoResetter
{
    /**
     * The demo company's tables that practice fills, in the order they can be emptied (documents first: their lines,
     * payments and returns go with them). Every table with a company_id is either here, in CASCADED or in KEEP; a test checks it.
     *
     * @var list<string>
     */
    public const TABLES = [
        'sales_documents',
        'purchases',
        'product_images',
        'products',
        'categories',
        'suppliers',
        'customers',
        'document_counters',
        'company_payment_methods',
        'tax_rates',
        'recargo_rates',
        'print_templates',
    ];

    /**
     * Tables that also carry a company_id but are emptied together with their documents (the foreign keys cascade).
     *
     * @var list<string>
     */
    public const CASCADED = [
        'sales_document_settlements',
        'sales_document_returns',
    ];

    /**
     * What is not emptied: the people, the demo's own subscription and the platform's records about it.
     *
     * @var list<string>
     */
    public const KEEP = [
        'users',
        'subscriptions',
        'payments',
        'billing_invoices',
        'notification_logs',
    ];

    public const LAST_RESET = 'demo:last_reset';

    public function __construct(private readonly DemoCompany $demo, private readonly DemoData $data) {}

    public function reset(): Company
    {
        $company = $this->demo->ensure();
        $owner = $this->demo->owner($company);

        DB::transaction(function () use ($company) {
            // Documents point at each other (a delivery note and its invoice): let go of those links, then delete.
            DB::table('sales_documents')->where('company_id', $company->id)->update([
                'converted_to_id' => null,
                'from_settlement_id' => null,
                'from_document_id' => null,
            ]);

            foreach (self::TABLES as $table) {
                DB::table($table)->where('company_id', $company->id)->delete();
            }

            // Numbering letters and starts the visitors tried out go too.
            $company->forceFill(['document_series' => null])->save();
        });

        $this->data->seed($company, $owner);
        Cache::forever(self::LAST_RESET, now()->toIso8601String());

        return $company->fresh();
    }
}
