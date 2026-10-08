<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** New companies, plans, subscriptions and payments default to EUR. Existing rows keep their currency. */
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->char('currency', 3)->default('EUR')->change());
        Schema::table('plans', fn (Blueprint $t) => $t->char('currency', 3)->default('EUR')->change());
        Schema::table('subscriptions', fn (Blueprint $t) => $t->char('plan_currency', 3)->default('EUR')->change());
        Schema::table('payments', fn (Blueprint $t) => $t->char('currency', 3)->default('EUR')->change());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->char('currency', 3)->default('USD')->change());
        Schema::table('plans', fn (Blueprint $t) => $t->char('currency', 3)->default('USD')->change());
        Schema::table('subscriptions', fn (Blueprint $t) => $t->char('plan_currency', 3)->default('USD')->change());
        Schema::table('payments', fn (Blueprint $t) => $t->char('currency', 3)->default('USD')->change());
    }
};
