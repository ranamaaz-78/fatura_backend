<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('sr_number', 64)->nullable();
            $table->string('article');
            $table->text('description')->nullable();
            $table->string('brand', 120)->nullable();
            $table->string('image_code', 120)->nullable();
            $table->string('barcode', 64);
            // The item arrived without a barcode of its own, so we made one and
            // its label still has to be printed.
            $table->boolean('barcode_generated')->default(false);

            $table->integer('quantity')->default(0);
            $table->integer('minimum_stock')->default(0);

            // Money is stored in cents so no rounding drifts through the catalog.
            $table->unsignedBigInteger('buying_price')->default(0);
            $table->unsignedBigInteger('selling_price')->default(0);
            $table->decimal('margin_percent', 8, 2)->default(0);
            $table->decimal('iva_percent', 5, 2)->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'barcode']);
            $table->unique(['company_id', 'sr_number']);
            $table->index(['company_id', 'article']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
