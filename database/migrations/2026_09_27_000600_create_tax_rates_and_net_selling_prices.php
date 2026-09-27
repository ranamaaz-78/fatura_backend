<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('rate', 5, 2);
            $table->timestamps();

            $table->unique(['company_id', 'rate']);
        });

        $now = now();
        $defaults = [
            ['name' => 'General', 'rate' => 21],
            ['name' => 'Reducido', 'rate' => 10],
            ['name' => 'Superreducido', 'rate' => 4],
            ['name' => 'Exento', 'rate' => 0],
        ];

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ($defaults as $row) {
                DB::table('tax_rates')->insert([
                    'company_id' => $companyId,
                    'name' => $row['name'],
                    'rate' => $row['rate'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Catalog prices used to include IVA. Store the net selling price so the
        // invoice can add the rate on top instead of taking it back out.
        foreach (DB::table('products')->orderBy('id')->lazyById() as $product) {
            $buying = (int) $product->buying_price;
            $selling = (int) $product->selling_price;
            $iva = (float) $product->iva_percent;

            if ($iva > 0) {
                $selling = (int) round($selling / (1 + $iva / 100));
            }

            $margin = $buying > 0 ? round(($selling - $buying) / $buying * 100, 2) : 0;

            DB::table('products')->where('id', $product->id)->update([
                'selling_price' => $selling,
                'margin_percent' => $margin,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
