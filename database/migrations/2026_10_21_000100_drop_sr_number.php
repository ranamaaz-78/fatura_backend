<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The "sr number" (serial / reference) is gone: products and the lines of documents no longer carry one. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'sr_number']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sr_number');
        });

        Schema::table('sales_document_lines', function (Blueprint $table) {
            $table->dropColumn('sr_number');
        });
    }

    public function down(): void
    {
        Schema::table('sales_document_lines', function (Blueprint $table) {
            $table->string('sr_number', 64)->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sr_number', 64)->nullable();
            $table->unique(['company_id', 'sr_number']);
        });
    }
};
