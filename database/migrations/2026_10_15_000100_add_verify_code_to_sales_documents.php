<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            // The secret in an invoice's QR code: whoever holds it can confirm the invoice is genuine.
            // Only invoices (facturas) carry one.
            $table->string('verify_code', 32)->nullable()->unique()->after('number');
        });

        // Invoices issued before this existed get one too, so their QR works the day it ships.
        DB::table('sales_documents')
            ->where('type', 'factura')
            ->whereNull('verify_code')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('sales_documents')->where('id', $row->id)->update(['verify_code' => Str::lower(Str::random(24))]);
            });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropUnique(['verify_code']);
            $table->dropColumn('verify_code');
        });
    }
};
