<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoResetter;
use Illuminate\Console\Command;

class ResetDemo extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Put the shared demo account back to its sample data (and create it the first time)';

    public function handle(DemoResetter $resetter): int
    {
        $company = $resetter->reset();

        $this->info("The demo ({$company->name}) is back to its sample data.");

        return self::SUCCESS;
    }
}
