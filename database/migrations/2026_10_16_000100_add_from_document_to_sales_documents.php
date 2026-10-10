<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            // The delivery note this invoice was made from. Like an invoice made from a proforma payment, it is
            // paper only: the delivery note already took the stock out and already counts as the sale.
            $table->unsignedBigInteger('from_document_id')->nullable()->index()->after('from_settlement_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropIndex(['from_document_id']);
            $table->dropColumn('from_document_id');
        });
    }
};
