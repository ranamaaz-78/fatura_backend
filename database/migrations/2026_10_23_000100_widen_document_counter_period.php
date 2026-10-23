<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document numbers now restart every month (F-202610/00001), so the counter's "year" column holds the month as
     * YYYYMM (202610) for documents, and still 0 for client / supplier codes. A small integer cannot hold that.
     * Counters from the yearly numbering (2026) cannot clash with a month (202610), so they are left as they are.
     */
    public function up(): void
    {
        Schema::table('document_counters', function (Blueprint $table) {
            $table->unsignedInteger('year')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('document_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->default(0)->change();
        });
    }
};
