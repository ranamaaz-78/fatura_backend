<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->foreignId('converted_to_id')
                ->nullable()
                ->after('voided_by')
                ->constrained('sales_documents')
                ->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->after('converted_to_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_to_id');
            $table->dropColumn('converted_at');
        });
    }
};
