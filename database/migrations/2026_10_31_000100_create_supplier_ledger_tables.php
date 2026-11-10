<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The supplier ledger: a record book of what the company bought from each supplier (a description and an amount)
     * and what it paid for it, in as many parts as needed. It is kept apart from Purchases on purpose: it holds no
     * products and never touches the stock.
     */
    public function up(): void
    {
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            // Kept on the entry, so the record still reads right if the supplier is deleted or renamed later.
            $table->string('supplier_name', 180);
            $table->string('number', 32);
            $table->date('bill_date');
            // What came from the supplier, in words ("40 LED lamps and 2 cartons of cable").
            $table->string('description', 500);
            // The number written on the supplier's own invoice or delivery note.
            $table->string('reference', 60)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('amount_cents');
            // The running total of the payments below: lists and "you owe" totals are one cheap sum.
            $table->unsignedBigInteger('paid_cents')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'bill_date']);
            $table->index(['company_id', 'supplier_id']);
        });

        Schema::create('supplier_bill_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('company_payment_methods')->nullOnDelete();
            $table->date('paid_on');
            $table->unsignedBigInteger('amount_cents');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // A payment typed by mistake is removed, but the record of it stays.
            $table->softDeletes();
            $table->timestamps();

            $table->index('supplier_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bill_payments');
        Schema::dropIfExists('supplier_bills');
    }
};
