<?php

namespace App\Services;

use App\Models\Product;
use App\Support\Barcode;
use RuntimeException;

class BarcodeAllocator
{
    /**
     * @param  array<int, string>  $reserved  codes handed out earlier in the same import
     */
    public static function forCompany(int $companyId, array $reserved = []): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $code = Barcode::randomEan13();

            if (in_array($code, $reserved, true)) {
                continue;
            }

            $taken = Product::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('barcode', $code)
                ->exists();

            if (! $taken) {
                return $code;
            }
        }

        throw new RuntimeException('Could not allocate a free barcode.');
    }
}
