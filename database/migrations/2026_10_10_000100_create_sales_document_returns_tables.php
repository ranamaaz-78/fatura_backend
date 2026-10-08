<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            // What the returned pieces were worth on the proforma. No money changes hands.
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->timestamps();

            $table->index('sales_document_id');
        });

        Schema::create('sales_document_return_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_document_return_id');
            $table->unsignedBigInteger('sales_document_line_id');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();

            $table->foreign('sales_document_return_id', 'sdrl_return_fk')
                ->references('id')->on('sales_document_returns')->cascadeOnDelete();
            $table->foreign('sales_document_line_id', 'sdrl_line_fk')
                ->references('id')->on('sales_document_lines')->cascadeOnDelete();
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            // Sum of the returns, so lists and reports can take it off what is owed.
            $table->unsignedBigInteger('returned_cents')->default(0)->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('sales_documents', fn (Blueprint $table) => $table->dropColumn('returned_cents'));
        Schema::dropIfExists('sales_document_return_lines');
        Schema::dropIfExists('sales_document_returns');
    }
};
