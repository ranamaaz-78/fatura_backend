<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** New companies and applications start in Spanish. Rows that already exist keep the language they have. */
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->string('locale', 5)->default('es')->change());
        Schema::table('applications', fn (Blueprint $table) => $table->string('locale', 5)->default('es')->change());
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->string('locale', 5)->default('en')->change());
        Schema::table('applications', fn (Blueprint $table) => $table->string('locale', 5)->default('en')->change());
    }
};
