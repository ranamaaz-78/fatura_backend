<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Support\Pricing;
use Illuminate\Support\Facades\DB;

class ProductImportService
{
    /**
     * Validates every row first and only writes when all of them pass, so a red
     * row can never slip into the catalog.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, errors: array<int, array{row: int, messages: array<int, string>}>}
     */
    public function import(int $companyId, array $rows): array
    {
        $prepared = [];
        $errors = [];
        $seenBarcodes = [];
        $seenSrNumbers = [];
        $allowedRates = TaxRate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('rate')
            ->map(fn ($rate) => round((float) $rate, 2))
            ->all();
        $supplierIndex = $this->supplierIndex($companyId);

        foreach ($rows as $index => $row) {
            $messages = [];
            $article = trim((string) ($row['article'] ?? ''));

            if ($article === '') {
                $messages[] = __('Article is required.');
            }

            $buying = $this->cents($row['buying_price'] ?? null);
            $selling = $this->cents($row['selling_price'] ?? null);
            $iva = Pricing::toNumber($row['iva_percent'] ?? null) ?? 0.0;

            if ($buying === null || $buying < 0) {
                $messages[] = __('Buying price is not a valid amount.');
            }

            if ($selling === null || $selling < 0) {
                $messages[] = __('Selling price is not a valid amount.');
            } elseif ($buying !== null && $selling <= $buying) {
                $messages[] = __('Selling price must be higher than the buying price.');
            }

            if ($iva < 0 || $iva > 100 || ! in_array(round($iva, 2), $allowedRates, true)) {
                $messages[] = __('Choose an IVA rate from your settings.');
            }

            $quantity = $this->wholeNumber($row['quantity'] ?? null);
            $minimumStock = $this->wholeNumber($row['minimum_stock'] ?? null);

            if ($quantity === null || $minimumStock === null) {
                $messages[] = __('Quantity and minimum stock must be whole numbers.');
            }

            $srNumber = $this->text($row['sr_number'] ?? null);
            $barcode = $this->text($row['barcode'] ?? null);

            if ($srNumber !== null) {
                if (in_array($srNumber, $seenSrNumbers, true)) {
                    $messages[] = __('This sr number is repeated in the file.');
                } elseif ($this->existsForCompany($companyId, 'sr_number', $srNumber)) {
                    $messages[] = __('This sr number already exists in your catalog.');
                }

                $seenSrNumbers[] = $srNumber;
            }

            if ($barcode !== null) {
                if (in_array($barcode, $seenBarcodes, true)) {
                    $messages[] = __('This barcode is repeated in the file.');
                } elseif ($this->existsForCompany($companyId, 'barcode', $barcode)) {
                    $messages[] = __('This barcode already exists in your catalog.');
                }

                $seenBarcodes[] = $barcode;
            }

            $supplierNeedle = $this->text($row['supplier'] ?? null);
            $supplierId = null;

            if ($supplierNeedle !== null) {
                $matched = $this->matchSupplier($supplierIndex, $supplierNeedle);

                if ($matched === false) {
                    $messages[] = __('More than one supplier matches this name.');
                } elseif ($matched === null) {
                    $messages[] = __('This supplier is not in your list.');
                } else {
                    $supplierId = $matched;
                }
            }

            if ($messages !== []) {
                $errors[] = ['row' => $index, 'messages' => $messages];

                continue;
            }

            $prepared[] = [
                'company_id' => $companyId,
                'sr_number' => $srNumber,
                'article' => $article,
                'description' => $this->text($row['description'] ?? null),
                'brand' => $this->text($row['brand'] ?? null),
                'image_code' => $this->text($row['image_code'] ?? null),
                'barcode' => $barcode,
                'barcode_generated' => $barcode === null,
                'quantity' => $quantity,
                'minimum_stock' => $minimumStock,
                'buying_price' => $buying,
                'selling_price' => $selling,
                'margin_percent' => Pricing::marginFromPrices((int) $buying, (int) $selling),
                'iva_percent' => round($iva, 2),
                'category_name' => $this->text($row['category'] ?? null),
                'supplier_id' => $supplierId,
            ];
        }

        if ($errors !== []) {
            return ['created' => 0, 'errors' => $errors];
        }

        DB::transaction(function () use ($companyId, $prepared, &$seenBarcodes) {
            $categories = $this->resolveCategories($companyId, $prepared);

            foreach ($prepared as $attributes) {
                $name = $attributes['category_name'];
                unset($attributes['category_name']);

                $attributes['category_id'] = $name === null ? null : $categories[mb_strtolower($name)];

                if ($attributes['barcode'] === null) {
                    $attributes['barcode'] = BarcodeAllocator::forCompany($companyId, $seenBarcodes);
                    $seenBarcodes[] = $attributes['barcode'];
                }

                Product::withoutGlobalScopes()->create($attributes);
            }
        });

        return ['created' => count($prepared), 'errors' => []];
    }

    /**
     * Category names coming from Excel are matched case insensitively and
     * created when they are new.
     *
     * @param  array<int, array<string, mixed>>  $prepared
     * @return array<string, int>
     */
    private function resolveCategories(int $companyId, array $prepared): array
    {
        $names = [];

        foreach ($prepared as $row) {
            if ($row['category_name'] !== null) {
                $names[mb_strtolower($row['category_name'])] = $row['category_name'];
            }
        }

        $existing = Category::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy(fn (Category $category) => mb_strtolower($category->name));

        $map = [];

        foreach ($names as $key => $name) {
            $map[$key] = $existing->has($key)
                ? $existing[$key]->id
                : Category::withoutGlobalScopes()->create(['company_id' => $companyId, 'name' => $name])->id;
        }

        return $map;
    }

    /**
     * Excel may name a supplier by name, company name or code. Unknown names
     * are errors — suppliers are not created from the sheet.
     *
     * @return array{unique: array<string, int>, ambiguous: array<string, true>}
     */
    private function supplierIndex(int $companyId): array
    {
        $unique = [];
        $ambiguous = [];

        $suppliers = Supplier::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get();

        foreach ($suppliers as $supplier) {
            foreach ([$supplier->name, $supplier->company_name, $supplier->code] as $label) {
                if (! is_string($label) || trim($label) === '') {
                    continue;
                }

                $key = mb_strtolower(trim($label));

                if (isset($ambiguous[$key])) {
                    continue;
                }

                if (isset($unique[$key]) && $unique[$key] !== $supplier->id) {
                    unset($unique[$key]);
                    $ambiguous[$key] = true;

                    continue;
                }

                $unique[$key] = $supplier->id;
            }
        }

        return ['unique' => $unique, 'ambiguous' => $ambiguous];
    }

    /**
     * @param  array{unique: array<string, int>, ambiguous: array<string, true>}  $index
     */
    private function matchSupplier(array $index, string $needle): int|false|null
    {
        $key = mb_strtolower($needle);

        if (isset($index['ambiguous'][$key])) {
            return false;
        }

        return $index['unique'][$key] ?? null;
    }

    private function existsForCompany(int $companyId, string $column, string $value): bool
    {
        return Product::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where($column, $value)
            ->exists();
    }

    private function cents(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function wholeNumber(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $number = Pricing::toNumber((string) $value);

        return $number === null || $number < 0 ? null : (int) round($number);
    }

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
