<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Local-only demo leads so the admin inbox has something to work with.
 */
class ApplicationSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('ApplicationSeeder only runs locally; skipping.');

            return;
        }

        $planIds = Plan::query()->pluck('id')->all();

        Application::factory()
            ->count(30)
            ->state(fn () => ['plan_id' => $planIds === [] ? null : fake()->randomElement($planIds)])
            ->create();
    }
}
