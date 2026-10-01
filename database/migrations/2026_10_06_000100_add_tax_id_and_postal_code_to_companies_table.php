<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // One field for NIF, NIE or CIF: whichever the business has.
            $table->string('tax_id', 32)->nullable()->after('email');
            $table->string('postal_code', 16)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['tax_id', 'postal_code']);
        });
    }
};
