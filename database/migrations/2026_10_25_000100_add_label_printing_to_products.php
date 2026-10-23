<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which barcode labels have been printed. A product whose barcode the system made ("barcode_generated") still
     * needs its label printed; once it has been, it stays in the list as printed and can be printed again.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // How many times its label was sent to print (a reprint counts), and when last.
            $table->unsignedInteger('label_print_count')->default(0)->after('barcode_generated');
            $table->timestamp('label_last_printed_at')->nullable()->after('label_print_count');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['label_print_count', 'label_last_printed_at']);
        });
    }
};
