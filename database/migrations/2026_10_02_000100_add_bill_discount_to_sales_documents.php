<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            // A discount on the whole bill: 'percent' (value is the percent) or 'amount' (value is cents).
            $table->string('discount_type', 10)->nullable()->after('tax_cents');
            $table->decimal('discount_value', 12, 2)->nullable()->after('discount_type');
            // What that discount took off the taxable base, in cents.
            $table->unsignedBigInteger('discount_cents')->default(0)->after('discount_value');
        });

        Schema::table('sales_document_lines', function (Blueprint $table) {
            // This line's share of the bill discount. Its base and tax are already net of it.
            $table->unsignedBigInteger('bill_discount_cents')->default(0)->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('sales_document_lines', function (Blueprint $table) {
            $table->dropColumn('bill_discount_cents');
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value', 'discount_cents']);
        });
    }
};
