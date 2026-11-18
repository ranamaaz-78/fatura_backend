<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A document that was never paid can be deleted. It stays in the table (so its number is never used again and
     * there is a record of it) but disappears from lists, reports and the dashboard. It can be restored.
     */
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->softDeletes()->after('voided_by');
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            // The quotation or delivery note this document was made from, re-opened by the delete and re-linked by a restore.
            $table->unsignedBigInteger('deleted_source_id')->nullable()->after('deleted_by');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropColumn('deleted_source_id');
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
