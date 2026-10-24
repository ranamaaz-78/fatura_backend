<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Windows app installer the public website offers for download. The platform admin uploads it; the newest row
     * is the one on offer, and publishing a new one removes the older ones (and their files).
     */
    public function up(): void
    {
        Schema::create('desktop_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20);
            $table->text('notes')->nullable();
            // Where the installer sits on the private disk, and the name it is downloaded under.
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desktop_releases');
    }
};
