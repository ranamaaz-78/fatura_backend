<?php

use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();

            // Snapshot of the plan as sold, so later plan edits never rewrite history.
            $table->string('plan_name');
            $table->decimal('plan_price', 10, 2);
            $table->char('plan_currency', 3)->default('USD');
            $table->enum('plan_interval', PlanInterval::values());
            $table->json('plan_features')->nullable();

            $table->enum('status', SubscriptionStatus::values())->default(SubscriptionStatus::Active->value)->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
