<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            // The only identifier that ever reaches a URL.
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Display label without an extension, and its lowercased form for uniqueness.
            $table->string('name', 180);
            $table->string('name_key', 180);
            // Private disk location. Renaming the label never touches this.
            $table->string('path');
            $table->string('mime', 60);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();

            $table->unique(['company_id', 'name_key']);
            // Search runs on the label, never on the stored path.
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
