<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The week now runs from the date written on the quotation, not the day the row was created.
     */
    public function up(): void
    {
        $days = (int) config('fatura.quotations.valid_days', 7);

        DB::table('sales_documents')
            ->where('type', 'quotation')
            ->orderBy('id')
            ->select(['id', 'issued_at'])
            ->each(function ($row) use ($days) {
                DB::table('sales_documents')->where('id', $row->id)->update([
                    'expires_at' => Carbon::parse($row->issued_at, 'UTC')
                        ->setTimezone(config('fatura.timezone'))
                        ->addDays($days)
                        ->endOfDay()
                        ->setTimezone('UTC'),
                ]);
            });
    }

    public function down(): void
    {
        // The earlier migration set the creation-based dates; nothing worth restoring.
    }
};
