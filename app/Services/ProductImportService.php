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
    /** The same limits as the product form (ProductController) and the database columns. */
    private const MAX = [
        'article' => 255,
        'description' => 2000,
        'brand' => 120,
        'short_code' => 120,
        'barcode' => 64,
        'category' => 120,
        'supplier' => 180,
    ];

    /** Quantity and minimum stock: the same ceiling as the product form. */
    private const MAX_PIECES = 9_999_999;

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
        $allowedRates = TaxRate::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->pluck('rate')
            ->map(fn ($rate) => round((float) $rate, 2))
            ->all();
        $supplierIndex = $this->supplierIndex($companyId);

        foreach ($rows as $index => $row) {
            $messages = [];
            // text() turns anything that is not plain text (a list, an object) into "missing" instead of crashing.
            $article = $this->text($row['article'] ?? null) ?? '';

            if ($article === '') {
                $messages[] = __('Article is required.');
            }

            foreach (self::MAX as $field => $max) {
                $value = $this->text($row[$field] ?? null);

                if ($value !== null && mb_strlen($value) > $max) {
                    $messages[] = __(':field is too long (at most :max characters).', ['field' => $this->label($field), 'max' => $max]);
                }
            }

            $buying = $this->cents($row['buying_price'] ?? null);
            $selling = $this->cents($row['selling_price'] ?? null);
            $iva = Pricing::toNumber(is_scalar($row['iva_percent'] ?? null) ? $row['iva_percent'] : null) ?? 0.0;

            if ($buying === null || $buying < 0) {
                $messages[] = __('Buying price is not a valid amount.');
            } elseif ($buying > Pricing::MAX_UNIT_CENTS) {
                $messages[] = __('Buying price is too high.');
            }

            if ($selling === null || $selling < 0) {
                $messages[] = __('Selling price is not a valid amount.');
            } elseif ($selling > Pricing::MAX_UNIT_CENTS) {
                $messages[] = __('Selling price is too high.');
            } elseif ($buying !== null && $selling <= $buying) {
                $messages[] = __('Selling price must be higher than the buying price.');
            }

            $ivaGiven = $row['iva_percent'] ?? null;

            if (($ivaGiven !== null && ! is_scalar($ivaGiven)) || $iva < 0 || $iva > 100 || ! in_array(round($iva, 2), $allowedRates, true)) {
                $messages[] = __('Choose an IVA rate from your settings.');
            }

            $quantity = $this->wholeNumber($row['quantity'] ?? null);
            $minimumStock = $this->wholeNumber($row['minimum_stock'] ?? null);

            if ($quantity === null || $minimumStock === null) {
                $messages[] = __('Quantity and minimum stock must be whole numbers.');
            } elseif ($quantity > self::MAX_PIECES || $minimumStock > self::MAX_PIECES) {
                $messages[] = __('Quantity and minimum stock can be at most :max.', ['max' => self::MAX_PIECES]);
            }

            $barcode = $this->text($row['barcode'] ?? null);

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
                'article' => $article,
                'description' => $this->text($row['description'] ?? null),
                'brand' => $this->text($row['brand'] ?? null),
                'short_code' => $this->text($row['short_code'] ?? null),
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

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $rounded = round((float) $value);

        // Beyond PHP's integer range the cast would wrap into a random number; "too high" is reported instead.
        return abs($rounded) >= PHP_INT_MAX ? PHP_INT_MAX : (int) $rounded;
    }

    private function wholeNumber(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (! is_scalar($value)) {
            return null;
        }

        // toNumber() drops anything that is not a digit (so "€ 12,50" reads), which would read "1e12" as 112.
        // A count of pieces has no letters in it, so a value with letters is refused instead of misread.
        if (is_string($value) && preg_match('/\p{L}/u', $value)) {
            return null;
        }

        $number = Pricing::toNumber((string) $value);

        if ($number === null || $number < 0) {
            return null;
        }

        // Kept inside the integer range; anything above the limit is reported by the caller.
        return $number >= PHP_INT_MAX ? PHP_INT_MAX : (int) round($number);
    }

    private function label(string $field): string
    {
        return match ($field) {
            'article' => __('Article'),
            'description' => __('Description'),
            'brand' => __('Brand'),
            'short_code' => __('Short code'),
            'barcode' => __('Barcode'),
            'category' => __('Category'),
            default => __('Supplier'),
        };
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
