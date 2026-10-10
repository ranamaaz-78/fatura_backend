<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What YK Digital Solutions bills a company for its subscription: one row per purchase or renewal.
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12)->default('new');
            $table->string('locale', 5)->default('es');
            $table->dateTime('issued_at');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('periods')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total', 12, 2);
            $table->string('status', 12)->default('paid');
            // Everything the document shows, as it was on the day: the company, the plan, the dates, the payment.
            $table->json('data');
            $table->dateTime('sent_at')->nullable();
            $table->text('send_error')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
