<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // The language of the whole company: its panel, documents, emails and WhatsApp messages.
            $table->string('locale', 5)->default('en')->after('currency');
        });

        Schema::table('users', function (Blueprint $table) {
            // Only the platform admin sets one; everyone else follows their company.
            $table->string('locale', 5)->nullable()->after('status');
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->string('locale', 5)->default('en')->after('country');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('name_es')->nullable()->after('name');
            $table->text('description_es')->nullable()->after('description');
            $table->json('features_es')->nullable()->after('features');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // The Spanish wording of the plan as it was when the term started: {"es": {"name": ..., "features": [...]}}.
            $table->json('plan_translations')->nullable()->after('plan_features');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('plan_translations'));
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn(['name_es', 'description_es', 'features_es']));
        Schema::table('applications', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
