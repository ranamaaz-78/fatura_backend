<?php

namespace App\Services;

use App\Models\BillingInvoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Support\Locales;
use Illuminate\Support\Facades\View;

/** Draws a billing invoice as an A4 PDF, in the language of the company it is billed to. */
class BillingInvoicePdf
{
    public function render(BillingInvoice $invoice): string
    {
        $html = Locales::using($invoice->locale, fn () => View::make('billing.invoice-pdf', $this->view($invoice))->render());

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isFontSubsettingEnabled', true);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** @return array<string, mixed> */
    private function view(BillingInvoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'issuer' => config('fatura.billing'),
            'support' => config('fatura.support'),
            'logo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('brand/logo-on-dark.png'))),
        ];
    }
}
