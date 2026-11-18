<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IVA on a whole purchase. `iva_mode` is none (the total is what it is), excluded (the typed prices are before IVA, so it
     * is added on top) or included (the typed prices already hold it). `total_cents` stays what is owed to the supplier, IVA
     * included, so the ledger and every total keep reading it; base and IVA are the two parts it is made of.
     */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('iva_mode', 10)->default('none')->after('notes');
            $table->decimal('iva_percent', 5, 2)->default(0)->after('iva_mode');
            $table->unsignedBigInteger('base_cents')->default(0)->after('iva_percent');
            $table->unsignedBigInteger('iva_cents')->default(0)->after('base_cents');
        });

        // What was written before has no IVA: its base is its total.
        \Illuminate\Support\Facades\DB::table('purchases')->update(['base_cents' => \Illuminate\Support\Facades\DB::raw('total_cents')]);
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['iva_mode', 'iva_percent', 'base_cents', 'iva_cents']);
        });
    }
};
