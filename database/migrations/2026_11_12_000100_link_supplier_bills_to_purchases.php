<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A purchase writes itself down in Supplier Payments: the entry it makes points back at it, so cancelling the
     * purchase can cancel the entry, and the screens can show where an entry came from.
     */
    public function up(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->foreignId('purchase_id')->nullable()->after('supplier_id')->constrained('purchases')->nullOnDelete();
            $table->unique('purchase_id');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->dropUnique(['purchase_id']);
            $table->dropConstrainedForeignId('purchase_id');
        });
    }
};
