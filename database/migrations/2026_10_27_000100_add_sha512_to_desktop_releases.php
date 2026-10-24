<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Windows app updates itself: its updater checks the installer against a SHA-512 (base64), so the release keeps
     * that next to the SHA-256 the admin panel shows.
     */
    public function up(): void
    {
        Schema::table('desktop_releases', function (Blueprint $table) {
            $table->string('sha512', 100)->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('desktop_releases', function (Blueprint $table) {
            $table->dropColumn('sha512');
        });
    }
};
