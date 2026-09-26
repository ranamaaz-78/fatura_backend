<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Support\Pricing;
use Illuminate\Support\Facades\DB;

class ProductImportService
{
    /** A row passes when its selling price is within one cent of the formula. */
    private const PRICE_TOLERANCE = 1;

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

        foreach ($rows as $index => $row) {
            $messages = [];
            $article = trim((string) ($row['article'] ?? ''));

            if ($article === '') {
                $messages[] = __('Article is required.');
            }

            $buying = $this->cents($row['buying_price'] ?? null);
            $selling = $this->cents($row['selling_price'] ?? null);
            $margin = Pricing::toNumber($row['margin_percent'] ?? null) ?? 0.0;
            $iva = Pricing::toNumber($row['iva_percent'] ?? null) ?? 0.0;

            if ($buying === null || $buying < 0) {
                $messages[] = __('Buying price is not a valid amount.');
            }

            if ($margin < 0 || $iva < 0 || $iva > 100) {
                $messages[] = __('Margin and IVA must be percentages of zero or more.');
            }

            if ($buying !== null && $buying >= 0 && $margin >= 0 && $iva >= 0) {
                $expected = Pricing::sellingPrice($buying, $margin, $iva);

                if ($selling === null) {
                    $selling = $expected;
                } elseif (abs($selling - $expected) > self::PRICE_TOLERANCE) {
                    $messages[] = __('Selling price does not match buying price, margin and IVA.');
                }
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
                'margin_percent' => round($margin, 2),
                'iva_percent' => round($iva, 2),
                'category_name' => $this->text($row['category'] ?? null),
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
