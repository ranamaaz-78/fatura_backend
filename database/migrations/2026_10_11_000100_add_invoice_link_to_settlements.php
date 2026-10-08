<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_document_settlements', function (Blueprint $table) {
            // The invoice made for this payment, so each payment is invoiced once.
            $table->foreignId('invoice_id')->nullable()->after('payment_method_id')
                ->constrained('sales_documents')->nullOnDelete();
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            // Set on an invoice made from a proforma payment. It is paper only: the proforma already counted the sale and the stock.
            $table->unsignedBigInteger('from_settlement_id')->nullable()->after('converted_at');
            $table->index('from_settlement_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropIndex(['from_settlement_id']);
            $table->dropColumn('from_settlement_id');
        });

        Schema::table('sales_document_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_id');
        });
    }
};
