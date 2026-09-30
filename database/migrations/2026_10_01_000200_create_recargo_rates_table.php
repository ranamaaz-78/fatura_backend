<?php

use App\Models\RecargoRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recargo_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('rate', 5, 2);
            $table->timestamps();

            $table->unique(['company_id', 'rate']);
        });

        DB::table('companies')->pluck('id')->each(
            fn (int $companyId) => RecargoRate::seedDefaults($companyId),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('recargo_rates');
    }
};
