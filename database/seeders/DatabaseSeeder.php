<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Only what the platform needs to work. No demo data: ApplicationSeeder (30 made-up leads) is for a developer
        // who wants a full inbox locally, and is run by hand: php artisan db:seed --class=ApplicationSeeder
        $this->call([
            SuperAdminSeeder::class,
            PlanSeeder::class,
            PaymentMethodSeeder::class,
        ]);
    }
}
