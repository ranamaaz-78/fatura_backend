<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            // Only a quotation has one: the last moment it can still be edited or turned into an invoice.
            $table->dateTime('expires_at')->nullable()->after('issued_at');
        });

        $days = (int) config('fatura.quotations.valid_days', 7);

        // Quotations that already exist get the same week, counted from the day they were created.
        DB::table('sales_documents')
            ->where('type', 'quotation')
            ->orderBy('id')
            ->select(['id', 'created_at'])
            ->each(function ($row) use ($days) {
                DB::table('sales_documents')->where('id', $row->id)->update([
                    'expires_at' => Carbon::parse($row->created_at)->addDays($days)->endOfDay(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
