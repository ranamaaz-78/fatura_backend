<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            // Recargo de equivalencia added to an invoice: the rate as it was, and the amount in cents.
            $table->decimal('recargo_percent', 5, 2)->nullable()->after('tax_cents');
            $table->unsignedBigInteger('recargo_cents')->default(0)->after('recargo_percent');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn(['recargo_percent', 'recargo_cents']);
        });
    }
};
