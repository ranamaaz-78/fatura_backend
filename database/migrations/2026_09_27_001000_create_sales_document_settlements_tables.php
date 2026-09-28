<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('company_payment_methods');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index('sales_document_id');
        });

        Schema::create('sales_document_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_document_settlement_id');
            $table->unsignedBigInteger('sales_document_line_id');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();

            $table->foreign('sales_document_settlement_id', 'sdsl_settlement_fk')
                ->references('id')
                ->on('sales_document_settlements')
                ->cascadeOnDelete();
            $table->foreign('sales_document_line_id', 'sdsl_line_fk')
                ->references('id')
                ->on('sales_document_lines')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_settlement_lines');
        Schema::dropIfExists('sales_document_settlements');
    }
};
