<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrintTemplateResource;
use App\Http\Resources\SalesDocumentResource;
use App\Models\Company;
use App\Models\PrintTemplate;
use App\Models\SalesDocument;
use App\Services\CompanyLogoStore;
use App\Services\SaleSettler;
use App\Support\Locales;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * What the QR code on an invoice opens. Anyone holding the code can confirm the invoice is genuine: show() says who
 * issued it, its number, date and total, and whether it was voided. document() gives the whole invoice, as printed,
 * to the same person. The code is a secret printed on the invoice itself, so it is only ever in the hands of
 * people who were given the invoice.
 */
class InvoiceVerificationController extends Controller
{
    use ApiResponse;

    private function find(string $code): ?SalesDocument
    {
        return strlen($code) >= 16
            ? SalesDocument::withoutGlobalScopes()->where('type', 'factura')->where('verify_code', $code)->first()
            : null;
    }

    public function show(string $code): JsonResponse
    {
        $invoice = $this->find($code);

        if ($invoice === null) {
            return $this->error(__('We could not find an invoice for this code.'), 404);
        }

        $company = Company::withoutGlobalScopes()->find($invoice->company_id);

        return $this->success([
            'number' => $invoice->number,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'total_cents' => (int) $invoice->total_cents,
            'currency' => $company?->currency,
            'voided' => $invoice->voided_at !== null,
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'company' => [
                'name' => $company?->name,
                'tax_id' => $company?->tax_id,
                'city' => $company?->city,
                'country' => $company?->country,
            ],
        ]);
    }

    /** The invoice as it is printed: the document, the issuer's details, the design it uses and its logo. */
    public function document(string $code): JsonResponse
    {
        $invoice = $this->find($code);

        if ($invoice === null) {
            return $this->error(__('We could not find an invoice for this code.'), 404);
        }

        $company = Company::withoutGlobalScopes()->findOrFail($invoice->company_id);
        $invoice->load([...SaleSettler::relations(), 'fromSettlement.document']);

        $template = PrintTemplate::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'factura')->first();
        $locale = Locales::normalize($company->document_locale) ?? Locales::normalize($company->locale) ?? Locales::DEFAULT;

        return $this->success([
            'document' => (new SalesDocumentResource($invoice))->resolve(),
            // Only what prints on an invoice: nothing internal (notes, status, owner) leaves the server.
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'tax_id' => $company->tax_id,
                'email' => $company->email,
                'phone' => $company->phone,
                'whatsapp' => $company->whatsapp,
                'address' => $company->address,
                'city' => $company->city,
                'postal_code' => $company->postal_code,
                'country' => $company->country,
                'currency' => $company->currency,
            ],
            'template' => $template !== null
                ? (new PrintTemplateResource($template))->resolve()
                : ['type' => 'factura'] + PrintTemplate::defaultFor('factura', $locale),
            'logo' => $this->logo($company),
            'locale' => $locale,
        ]);
    }

    /** The logo as a data URL, since the file itself sits behind the company's login. */
    private function logo(Company $company): ?string
    {
        if (! $company->logo_path) {
            return null;
        }

        $disk = Storage::disk(CompanyLogoStore::DISK);

        if (! $disk->exists($company->logo_path)) {
            return null;
        }

        return 'data:'.($disk->mimeType($company->logo_path) ?: 'image/png').';base64,'.base64_encode((string) $disk->get($company->logo_path));
    }
}
