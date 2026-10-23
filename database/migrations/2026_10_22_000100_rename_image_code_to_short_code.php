<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What was the "image code" is the product's short code. The values stay as they are. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('image_code', 'short_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('short_code', 'image_code');
        });
    }
};
