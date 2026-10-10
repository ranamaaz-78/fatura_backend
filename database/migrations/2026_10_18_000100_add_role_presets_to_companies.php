<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // The owner's own version of a role's starting permissions: {"cashier": ["invoices.view", ...]}.
            // Roles not listed keep the standard ones. Members already created are not touched.
            $table->json('role_presets')->nullable()->after('document_locale');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('role_presets'));
    }
};
