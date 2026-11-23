<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft invoices. A draft already holds its number (the next invoice takes the one after it) and its date, but moves
 * no stock and counts as no sale until it is issued, when draft_at goes back to null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->timestamp('draft_at')->nullable()->after('issued_at');
            $table->index(['company_id', 'draft_at']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'draft_at']);
            $table->dropColumn('draft_at');
        });
    }
};
