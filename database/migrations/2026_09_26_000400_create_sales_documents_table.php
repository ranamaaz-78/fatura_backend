<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('nif', 32)->nullable();
            $table->string('nie', 32)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'phone']);
        });

        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // factura, albaran, abono reset each year. client does not, so its year is 0.
            $table->string('kind', 20);
            $table->unsignedSmallInteger('year')->default(0);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'kind', 'year']);
        });

        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20);
            $table->string('number', 32);
            $table->dateTime('issued_at');
            $table->string('payment_status', 20)->default('pending');
            // Copied at issue time, so a later customer edit does not rewrite the document.
            $table->string('client_code', 32)->nullable();
            $table->string('client_name');
            $table->string('client_company')->nullable();
            $table->string('client_phone', 40)->nullable();
            $table->string('client_nif', 32)->nullable();
            $table->string('client_nie', 32)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('base_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'type', 'issued_at']);
            $table->index(['company_id', 'payment_status']);
        });

        Schema::create('sales_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('sr_number', 64)->nullable();
            $table->string('article');
            $table->text('description')->nullable();
            $table->integer('quantity');
            // Net of IVA. The tax is added on the line, the way a factura shows it.
            $table->unsignedBigInteger('unit_price');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('iva_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('base_cents');
            $table->unsignedBigInteger('tax_cents');
            $table->unsignedBigInteger('total_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_lines');
        Schema::dropIfExists('sales_documents');
        Schema::dropIfExists('document_counters');
        Schema::dropIfExists('customers');
    }
};
