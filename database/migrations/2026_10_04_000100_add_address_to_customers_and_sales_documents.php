<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('nie');
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            // A snapshot, like the client's name and phone: the document keeps the address it was issued to.
            $table->string('client_address', 255)->nullable()->after('client_nie');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn('client_address');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
