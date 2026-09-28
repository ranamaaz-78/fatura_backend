<?php

use App\Models\Company;
use App\Models\PrintTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('primary_color', 7);
            $table->string('font_key', 32);
            $table->text('footer_notes')->nullable();
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_signature')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'type']);
        });

        Company::query()->pluck('id')->each(
            fn (int $companyId) => PrintTemplate::seedDefaults($companyId),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('print_templates');
    }
};
