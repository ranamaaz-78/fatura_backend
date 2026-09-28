<?php

use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        Schema::table('sales_documents', function (Blueprint $table) {
            $table->foreignId('payment_method_id')
                ->nullable()
                ->after('payment_status')
                ->constrained('company_payment_methods')
                ->nullOnDelete();
        });

        Company::query()->pluck('id')->each(
            fn (int $companyId) => CompanyPaymentMethod::seedDefaults($companyId),
        );
    }

    public function down(): void
    {
        Schema::table('sales_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });

        Schema::dropIfExists('company_payment_methods');
    }
};
