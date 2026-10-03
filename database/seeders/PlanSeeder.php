<?php

namespace Database\Seeders;

use App\Enums\PlanInterval;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        Plan::updateOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'Starter',
                'description' => 'Everything a small business needs to invoice clients and get paid.',
                'name_es' => 'Starter',
                'description_es' => 'Todo lo que un pequeño negocio necesita para facturar a sus clientes y cobrar.',
                'features_es' => [
                    'Facturas ilimitadas',
                    'Clientes ilimitados',
                    'Presupuestos y albaranes',
                    'Pagos y pagos parciales',
                    'Exportación e impresión en PDF',
                    'Soporte por email',
                ],
                'price' => 20.00,
                'currency' => 'USD',
                'interval' => PlanInterval::Month,
                'features' => [
                    'Unlimited invoices',
                    'Unlimited clients',
                    'Quotes and delivery notes',
                    'Payments and partial payments',
                    'PDF export and print',
                    'Email support',
                ],
                'max_users' => 3,
                'max_invoices' => null,
                'is_featured' => true,
                'is_active' => true,
                'sort_order' => 1,
            ],
        );
    }
}
