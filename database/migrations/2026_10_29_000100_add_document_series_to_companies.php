<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a company numbers its invoices, delivery notes, quotations and proformas: the letters in front of the number
     * and the number each month starts from, e.g. {"factura": {"prefix": "ACME", "start": 341}}. Empty means the
     * defaults (F, AL, Q, PF, starting at 1).
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->json('document_series')->nullable()->after('role_presets');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('document_series'));
    }
};
