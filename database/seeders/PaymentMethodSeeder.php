<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            ['slug' => 'cash', 'name' => 'Cash', 'description' => 'Paid in cash, recorded manually.'],
            ['slug' => 'bank-transfer', 'name' => 'Bank transfer', 'description' => 'Direct transfer to the company bank account.'],
            ['slug' => 'card-offline', 'name' => 'Card (offline)', 'description' => 'Card taken over the phone or in person.'],
            ['slug' => 'jazzcash', 'name' => 'JazzCash', 'description' => 'JazzCash mobile wallet.'],
            ['slug' => 'easypaisa', 'name' => 'Easypaisa', 'description' => 'Easypaisa mobile wallet.'],
            ['slug' => 'paypal', 'name' => 'PayPal', 'description' => 'PayPal transfer or invoice.'],
        ];

        foreach ($methods as $index => $method) {
            PaymentMethod::updateOrCreate(
                ['slug' => $method['slug']],
                [
                    'name' => $method['name'],
                    'description' => $method['description'],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
