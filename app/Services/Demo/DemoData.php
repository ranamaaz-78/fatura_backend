<?php

namespace App\Services\Demo;

use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyPaymentMethod;
use App\Models\Customer;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\RecargoRate;
use App\Models\SalesDocument;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\DocumentNumber;
use App\Services\PurchaseRecorder;
use App\Services\SaleIssuer;
use App\Services\SaleSettler;
use App\Support\Barcode;
use App\Support\IssueDate;
use App\Support\Pricing;
use Illuminate\Support\Facades\Auth;

/**
 * The sample business the demo starts from every night: categories, suppliers, clients, about thirty-five products with
 * real barcodes, and a few weeks of invoices, delivery notes, quotations, a proforma and two purchases.
 *
 * Plain arrays, not model factories: the live server installs without dev packages, so Faker is not there. Sales and
 * purchases go through the same services the screens use, so numbers, stock and totals are consistent.
 */
class DemoData
{
    /** @var list<string> */
    private const CATEGORIES = ['Lighting', 'Electrical', 'Smart home', 'Batteries', 'Cables', 'Tools'];

    /** name, company, phone, tax id, address */
    private const SUPPLIERS = [
        ['Luz del Sur', 'Luz del Sur S.L.', '+34 911 220 001', 'B11111111'],
        ['Norte Eléctrico', 'Norte Eléctrico S.A.', '+34 911 220 002', 'A22222222'],
        ['Electro Hogar', 'Electro Hogar S.L.', '+34 911 220 003', 'B33333333'],
        ['Cables y Más', 'Cables y Más S.L.', '+34 911 220 004', 'B44444444'],
        ['Herramientas Ruiz', 'Herramientas Ruiz', '+34 911 220 005', 'B55555555'],
    ];

    /** name, company, phone, tax id, address */
    private const CUSTOMERS = [
        ['Lucía Fernández', 'Floristería Lucía', '+34 611 200 001', '12345678Z', 'Calle Alcalá 45, 28014 Madrid'],
        ['Carlos Ruiz', 'Ruiz Hermanos S.L.', '+34 611 200 002', 'B66666666', 'Av. de América 12, 28028 Madrid'],
        ['Ana Martín', null, '+34 611 200 003', '23456789D', 'Calle Mayor 8, 28013 Madrid'],
        ['Javier López', 'Café Central', '+34 611 200 004', '34567890V', 'Plaza Mayor 3, 28012 Madrid'],
        ['Marta Sánchez', 'Boutique Marta', '+34 611 200 005', '45678901G', 'Calle Serrano 90, 28006 Madrid'],
        ['Pedro Gómez', 'Gómez Reformas', '+34 611 200 006', 'B77777777', 'Calle Toledo 21, 28005 Madrid'],
        ['Elena Díaz', null, '+34 611 200 007', '56789012J', 'Calle Atocha 5, 28012 Madrid'],
        ['Sergio Navarro', 'Navarro & Co', '+34 611 200 008', 'B88888888', 'Calle Goya 40, 28001 Madrid'],
        ['Laura Torres', 'Peluquería Laura', '+34 611 200 009', '67890123K', 'Calle Bravo Murillo 77, 28003 Madrid'],
        ['Diego Romero', 'Romero Instalaciones', '+34 611 200 010', 'B99999999', 'Calle Embajadores 18, 28012 Madrid'],
    ];

    /** article, brand, category index, supplier index, buying (cents), selling (cents, net of IVA), IVA %, stock, minimum */
    private const PRODUCTS = [
        ['Lámpara Nórdica Roble', 'LUMEN', 0, 0, 2900, 4590, 21, 18, 4],
        ['Lámpara de mesa Aura', 'LUMEN', 0, 0, 1800, 2990, 21, 25, 5],
        ['Aplique pared negro', 'LUMEN', 0, 0, 2200, 3990, 21, 11, 3],
        ['Plafón LED redondo 24W', 'Philips', 0, 0, 1300, 2290, 21, 32, 6],
        ['Bombilla LED E27 9W', 'Philips', 0, 0, 280, 590, 21, 140, 30],
        ['Bombilla LED E14 5W', 'Philips', 0, 0, 240, 520, 21, 110, 30],
        ['Tira LED 5m RGB', 'Govee', 0, 0, 1520, 2990, 21, 16, 6],
        ['Foco empotrable GU10', 'LUMEN', 0, 0, 690, 1250, 21, 40, 10],
        ['Regleta 6 tomas', 'Orbegozo', 1, 1, 1040, 1890, 21, 34, 8],
        ['Regleta 3 tomas con interruptor', 'Orbegozo', 1, 1, 720, 1390, 21, 28, 8],
        ['Alargador 5m', 'Orbegozo', 1, 1, 650, 1190, 21, 45, 10],
        ['Enchufe Schuko empotrable', 'Simon', 1, 1, 190, 390, 21, 90, 20],
        ['Interruptor simple blanco', 'Simon', 1, 1, 150, 320, 21, 120, 25],
        ['Interruptor inteligente WiFi', 'Sonoff', 2, 2, 1340, 2450, 21, 22, 5],
        ['Enchufe inteligente WiFi', 'Sonoff', 2, 2, 980, 1790, 21, 30, 6],
        ['Bombilla inteligente RGB', 'Govee', 2, 2, 1100, 1990, 21, 26, 6],
        ['Sensor de movimiento', 'Sonoff', 2, 2, 860, 1590, 21, 18, 5],
        ['Cámara WiFi interior', 'Tapo', 2, 2, 1990, 3490, 21, 12, 3],
        ['Pila AA pack 8', 'Duracell', 3, 2, 460, 790, 10, 75, 20],
        ['Pila AAA pack 8', 'Duracell', 3, 2, 440, 760, 10, 70, 20],
        ['Pila botón CR2032 pack 4', 'Energizer', 3, 2, 300, 550, 10, 55, 15],
        ['Cargador USB-C 65W', 'Anker', 4, 3, 1790, 3290, 21, 20, 5],
        ['Cable USB-C 1m', 'Anker', 4, 3, 360, 790, 21, 85, 20],
        ['Cable HDMI 2m', 'Anker', 4, 3, 420, 890, 21, 48, 10],
        ['Cable Ethernet Cat6 5m', 'Ugreen', 4, 3, 380, 790, 21, 40, 10],
        ['Adaptador USB-C a HDMI', 'Ugreen', 4, 3, 890, 1690, 21, 15, 4],
        ['Destornillador de precisión', 'Ruiz', 5, 4, 540, 990, 21, 26, 6],
        ['Juego de puntas 32 piezas', 'Ruiz', 5, 4, 780, 1490, 21, 19, 5],
        ['Cinta aislante pack 5', 'Ruiz', 5, 4, 260, 490, 21, 60, 15],
        ['Taladro inalámbrico 18V', 'Ruiz', 5, 4, 4900, 8990, 21, 8, 2],
        ['Medidor láser 40m', 'Ruiz', 5, 4, 2400, 4490, 21, 9, 2],
        ['Detector de tensión', 'Ruiz', 5, 4, 640, 1190, 21, 14, 4],
        ['Linterna LED recargable', 'Philips', 0, 0, 990, 1790, 21, 21, 5],
        ['Candado de seguridad', 'Ruiz', 5, 4, 480, 890, 21, 33, 8],
    ];

    public function __construct(
        private readonly DocumentNumber $numbers,
        private readonly SaleIssuer $sales,
        private readonly SaleSettler $settler,
        private readonly PurchaseRecorder $purchases,
    ) {}

    public function seed(Company $company, User $owner): void
    {
        // The services read the signed-in person's company, so act as the demo owner while the sample is written.
        $previous = Auth::user();
        Auth::setUser($owner);

        try {
            $this->defaults($company);
            $categories = $this->categories($company);
            $suppliers = $this->suppliers($company);
            $customers = $this->customers($company);
            $products = $this->products($company, $categories, $suppliers);
            $methods = $this->paymentMethods($company);

            $this->purchases($owner, $suppliers, $products);
            $this->documents($owner, $customers, $products, $methods);
            $this->lowStock($products);
        } finally {
            $previous !== null ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    /** The rates, payment methods and print templates every new company starts with. */
    private function defaults(Company $company): void
    {
        TaxRate::seedDefaults((int) $company->id);
        CompanyPaymentMethod::seedDefaults((int) $company->id, 'en');
        PrintTemplate::seedDefaults((int) $company->id, 'en');

        foreach (RecargoRate::DEFAULTS as $row) {
            RecargoRate::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id, 'rate' => $row['rate']], ['name' => $row['name']]);
        }
    }

    /** @return list<Category> */
    private function categories(Company $company): array
    {
        return array_map(fn (string $name) => Category::create(['company_id' => $company->id, 'name' => $name]), self::CATEGORIES);
    }

    /** @return list<Supplier> */
    private function suppliers(Company $company): array
    {
        return array_map(fn (array $row) => Supplier::create([
            'company_id' => $company->id,
            'code' => $this->numbers->take((int) $company->id, 'supplier'),
            'name' => $row[0],
            'company_name' => $row[1],
            'phone' => $row[2],
            'nif' => $row[3],
            'is_active' => true,
        ]), self::SUPPLIERS);
    }

    /** @return list<Customer> */
    private function customers(Company $company): array
    {
        return array_map(fn (array $row) => Customer::create([
            'company_id' => $company->id,
            'code' => $this->numbers->take((int) $company->id, 'client'),
            'name' => $row[0],
            'company_name' => $row[1],
            'phone' => $row[2],
            'nif' => $row[3],
            'address' => $row[4],
            'is_active' => true,
        ]), self::CUSTOMERS);
    }

    /**
     * @param  list<Category>  $categories
     * @param  list<Supplier>  $suppliers
     * @return list<Product>
     */
    private function products(Company $company, array $categories, array $suppliers): array
    {
        $made = [];

        foreach (self::PRODUCTS as $index => [$article, $brand, $category, $supplier, $buying, $selling, $iva, $stock, $minimum]) {
            // A real EAN-13: 84 (Spain), a running number, and the check digit.
            $twelve = '84'.str_pad((string) (7000 + $index), 10, '0', STR_PAD_LEFT);

            $made[] = Product::create([
                'company_id' => $company->id,
                'category_id' => $categories[$category]->id,
                'supplier_id' => $suppliers[$supplier]->id,
                'article' => $article,
                'brand' => $brand,
                'barcode' => $twelve.Barcode::checkDigit($twelve),
                'barcode_generated' => false,
                'quantity' => $stock,
                'minimum_stock' => $minimum,
                'buying_price' => $buying,
                'selling_price' => $selling,
                'margin_percent' => Pricing::marginFromPrices($buying, $selling),
                'iva_percent' => $iva,
            ]);
        }

        return $made;
    }

    /** @return array{cash: int, card: int} */
    private function paymentMethods(Company $company): array
    {
        $methods = CompanyPaymentMethod::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('sort_order')->orderBy('id')->get();

        return [
            'cash' => (int) ($methods->firstWhere('name', 'Cash')?->id ?? $methods->first()?->id),
            'card' => (int) ($methods->firstWhere('name', 'Card')?->id ?? $methods->first()?->id),
        ];
    }

    /**
     * Two deliveries from suppliers, which also put the stock up.
     *
     * @param  list<Supplier>  $suppliers
     * @param  list<Product>  $products
     */
    private function purchases(User $owner, array $suppliers, array $products): void
    {
        $this->purchases->record($owner, [
            'supplier_id' => $suppliers[0]->id,
            'purchased_on' => now()->subDays(12)->toDateString(),
            'supplier_invoice_no' => 'LS-2041',
            'notes' => 'Monthly lighting order.',
            'lines' => [
                ['product_id' => $products[4]->id, 'quantity' => 60, 'unit_cost' => 280],
                ['product_id' => $products[5]->id, 'quantity' => 40, 'unit_cost' => 240],
                ['product_id' => $products[7]->id, 'quantity' => 20, 'unit_cost' => 690],
            ],
        ]);

        $this->purchases->record($owner, [
            'supplier_id' => $suppliers[2]->id,
            'purchased_on' => now()->subDays(5)->toDateString(),
            'supplier_invoice_no' => 'EH-889',
            'notes' => null,
            'lines' => [
                ['product_id' => $products[13]->id, 'quantity' => 10, 'unit_cost' => 1340],
                ['product_id' => $products[14]->id, 'quantity' => 12, 'unit_cost' => 980],
            ],
        ]);
    }

    /**
     * @param  list<Customer>  $customers
     * @param  list<Product>  $products
     * @param  array{cash: int, card: int}  $methods
     */
    private function documents(User $owner, array $customers, array $products, array $methods): void
    {
        // type, days ago, customer, [[product, quantity, discount %]], how it was paid (null = still pending)
        $plan = [
            ['factura', 19, 0, [[4, 6, 0], [7, 4, 0]], 'cash'],
            ['factura', 17, 1, [[0, 2, 0], [2, 1, 10]], 'card'],
            ['factura', 14, 2, [[21, 2, 0], [22, 3, 0]], 'cash'],
            ['factura', 11, 3, [[8, 3, 0], [9, 2, 0], [10, 2, 0]], 'card'],
            ['factura', 9, 4, [[13, 2, 0], [14, 3, 5]], 'cash'],
            ['factura', 6, 5, [[29, 1, 0], [28, 2, 0]], null],
            ['factura', 3, 6, [[18, 4, 0], [19, 4, 0]], 'cash'],
            ['factura', 1, 7, [[1, 3, 0], [6, 2, 0]], null],
            ['albaran', 8, 8, [[27, 2, 0], [26, 3, 0]], 'cash'],
            ['albaran', 2, 9, [[24, 5, 0], [23, 4, 0]], null],
            ['quotation', 4, 1, [[17, 2, 0], [16, 4, 0]], null],
            ['quotation', 1, 4, [[3, 10, 5]], null],
        ];

        foreach ($plan as [$type, $daysAgo, $customer, $lines, $paid]) {
            $this->sell($owner, $type, $daysAgo, $customers[$customer], $this->lines($products, $lines), $paid === null ? null : $methods[$paid]);
        }

        // A proforma with one payment already recorded against it.
        $proforma = $this->sell($owner, 'proforma', 7, $customers[5], $this->lines($products, [[30, 2, 0], [29, 1, 0]]), null);
        $first = $proforma->lines->first();
        $this->settler->settle($owner, $proforma, [
            'payment_method_id' => $methods['cash'],
            'lines' => [['line_id' => $first->id, 'quantity' => 1, 'unit_price' => (int) $first->unit_price]],
        ]);
    }

    /**
     * @param  list<Product>  $products
     * @param  list<array{0: int, 1: int, 2: int}>  $picks
     * @return list<array<string, mixed>>
     */
    private function lines(array $products, array $picks): array
    {
        return array_map(fn (array $pick) => [
            'product_id' => $products[$pick[0]]->id,
            'article' => $products[$pick[0]]->article,
            'description' => null,
            'quantity' => $pick[1],
            'unit_price' => (int) $products[$pick[0]]->selling_price,
            'discount_percent' => $pick[2],
            'iva_percent' => (float) $products[$pick[0]]->iva_percent,
        ], $picks);
    }

    /** @param  list<array<string, mixed>>  $lines */
    private function sell(User $owner, string $type, int $daysAgo, Customer $customer, array $lines, ?int $methodId): SalesDocument
    {
        $issued = IssueDate::stored(IssueDate::parse(now()->subDays($daysAgo)->setTime(10 + ($daysAgo % 7), 15)->toIso8601String()));

        return $this->sales->issue($owner, [
            'type' => $type,
            'issued_at' => $issued,
            'number_period' => IssueDate::period($issued),
            'payment_status' => $methodId === null ? 'pending' : 'paid',
            'payment_method_id' => $methodId,
            'customer_id' => $customer->id,
            'save_customer' => false,
            'client_name' => $customer->name,
            'client_company' => $customer->company_name,
            'client_phone' => $customer->phone,
            'client_nif' => $customer->nif,
            'client_nie' => null,
            'client_address' => $customer->address,
            'discount_type' => null,
            'discount_value' => null,
            'recargo_rate_id' => null,
            'lines' => $lines,
        ]);
    }

    /** A few products running low or out, so the dashboard and the stock alerts have something to show. */
    private function lowStock(array $products): void
    {
        $products[7]->update(['quantity' => 3]);
        $products[13]->update(['quantity' => 0]);
        $products[17]->update(['quantity' => 2]);
        $products[30]->update(['quantity' => 1]);
    }
}
