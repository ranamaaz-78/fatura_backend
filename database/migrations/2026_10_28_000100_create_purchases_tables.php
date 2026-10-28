<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a company bought from its suppliers. One purchase is one delivery (a batch): one supplier, one date, and a
     * line for each product with how many came in and at what rate. Saving it adds the pieces to the stock.
     */
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            // Kept on the purchase, so the record still reads right if the supplier is deleted or renamed later.
            $table->string('supplier_name', 180);
            $table->string('number', 32);
            $table->date('purchased_on');
            // The number written on the supplier's own invoice or delivery note.
            $table->string('supplier_invoice_no', 60)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'purchased_on']);
            $table->index(['company_id', 'supplier_id']);
        });

        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('article', 180);
            $table->unsignedInteger('quantity');
            // What one piece cost, in cents (IVA is not part of it, like the product's buying price).
            $table->unsignedBigInteger('unit_cost');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_lines');
        Schema::dropIfExists('purchases');
    }
};
