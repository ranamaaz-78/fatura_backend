<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email', 190)->nullable()->after('phone');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('email', 190)->nullable()->after('phone');
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            // A snapshot, like the client's name, phone and address: the document keeps the email it was issued to.
            $table->string('client_email', 190)->nullable()->after('client_address');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn('client_email');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('email');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
