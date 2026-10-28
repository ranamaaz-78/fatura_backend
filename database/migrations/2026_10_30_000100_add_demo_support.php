<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared demo account every customer can practice in. The demo is a company flagged "is_demo"; each time a
     * customer goes into it a row is kept in demo_sessions, only for the "how many practiced" numbers in the admin panel.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('status');
        });

        Schema::create('demo_sessions', function (Blueprint $table) {
            $table->id();
            // The real customer who went in (not the demo user, which is the same for everybody).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('exited_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('entered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_sessions');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn('is_demo'));
    }
};
